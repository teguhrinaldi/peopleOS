<?php

namespace App\Services\Overtime;

use App\Enums\PayrollStatus;
use App\Enums\RequestStatus;
use App\Models\Employee;
use App\Models\OvertimePayrollInclusion;
use App\Models\OvertimeRequest;
use App\Models\PayrollItemLine;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Services\Payroll\Contracts\PayrollEarningSource;
use App\Services\Payroll\Earnings\EarningContext;
use App\Services\Payroll\Earnings\EarningLine;
use App\Services\Payroll\Earnings\EarningSourceConflict;
use App\Services\Payroll\Money;
use App\Support\Settings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

/**
 * Overtime rule engine as a payroll earning source (enabled by Settings `overtime.engine_enabled`).
 *
 * Candidates of a run: APPROVED requests, payroll_status not ADJUSTED / LEGACY, without a live inclusion in
 * ANOTHER run, and
 *   REGULAR period:    work_date inside the period, or (Settings overtime.carry_over_late_approvals, default on)
 *                      work_date inside an earlier REGULAR period that is already FINALIZED / PAID (late approval)
 *   ADJUSTMENT period: work_date inside the adjusted period, employee paid in the original run
 * PENDING, REJECTED and CANCELLED requests are never candidates.
 *
 * Per employee the requests are processed in (work_date, start_at, id) order so day caps and hour bands are
 * deterministic. One payroll line per (day type, multiplier, hourly rate); each line carries its request ids.
 */
class OvertimeEarningSource implements PayrollEarningSource
{
    public const KEY = 'overtime';

    private const DAY_LABELS = ['workday' => 'Workday', 'rest_day' => 'Rest day', 'holiday' => 'Holiday'];

    /** @var Collection<int, Collection<int, OvertimeRequest>> employee id => requests */
    private Collection $candidates;

    /** @var array<int, array<string, int>> employee id => work date => minutes already paid by other runs */
    private array $priorDay = [];

    /** @var array<int, int> employee id => minutes already paid by other runs for this period's dates */
    private array $priorPeriod = [];

    /** @var list<array<string, mixed>> */
    private array $excluded = [];

    private int $included = 0;

    public function __construct(
        private readonly Settings $settings,
        private readonly OvertimeEligibility $eligibility,
        private readonly OvertimeRuleResolver $rules,
        private readonly OvertimeCalculator $calculator,
    ) {
        $this->candidates = collect();
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function enabled(): bool
    {
        return $this->settings->bool('overtime.engine_enabled');
    }

    public function prepare(PayrollPeriod $period, PayrollRun $run, array $employeeIds): void
    {
        $this->excluded = [];
        $this->included = 0;
        $this->rules->forget();

        $from = $period->start_date->toDateString();
        $to = $period->end_date->toDateString();
        $lockedRanges = [];
        if (! $period->isAdjustment() && $this->settings->bool('overtime.carry_over_late_approvals')) {
            $lockedRanges = PayrollPeriod::query()
                ->where('company_id', $period->company_id)
                ->where('type', 'REGULAR')
                ->whereIn('status', [PayrollStatus::Finalized->value, PayrollStatus::Paid->value])
                ->where('end_date', '<', $from)
                ->get(['start_date', 'end_date'])
                ->map(fn (PayrollPeriod $p) => [$p->start_date->toDateString(), $p->end_date->toDateString()])
                ->all();
        }
        $earliest = min(array_merge([$from], array_column($lockedRanges, 0)));

        $rows = OvertimeRequest::query()
            ->with('employee')
            ->whereIn('employee_id', $employeeIds)
            ->where('status', RequestStatus::Approved->value)
            ->whereNotIn('payroll_status', [OvertimeRequest::PAYROLL_ADJUSTED, OvertimeRequest::PAYROLL_LEGACY])
            ->whereDate('work_date', '>=', $earliest)
            ->whereDate('work_date', '<=', $to)
            ->whereDoesntHave('inclusion', fn ($q) => $q->where('payroll_run_id', '!=', $run->id))
            ->orderBy('employee_id')->orderBy('work_date')->orderBy('start_at')->orderBy('id')
            ->get()
            ->filter(function (OvertimeRequest $o) use ($from, $lockedRanges) {
                $date = $o->work_date->toDateString();
                if ($date >= $from) {
                    return true;
                }
                foreach ($lockedRanges as [$s, $e]) {
                    if ($date >= $s && $date <= $e) {
                        return true;
                    }
                }

                return false;
            });

        $this->candidates = $rows->groupBy('employee_id');

        // Minutes other runs already paid (live inclusions) count against the day / period caps.
        $this->priorDay = [];
        $this->priorPeriod = [];
        $paid = OvertimePayrollInclusion::query()
            ->join('overtime_requests', 'overtime_requests.id', '=', 'overtime_payroll_inclusions.overtime_request_id')
            ->whereNotNull('overtime_payroll_inclusions.active_request_id')
            ->where('overtime_payroll_inclusions.payroll_run_id', '!=', $run->id)
            ->whereIn('overtime_payroll_inclusions.employee_id', $employeeIds)
            ->whereDate('overtime_requests.work_date', '>=', $earliest)
            ->whereDate('overtime_requests.work_date', '<=', $to)
            ->get(['overtime_payroll_inclusions.employee_id', 'overtime_requests.work_date', 'overtime_payroll_inclusions.minutes']);
        foreach ($paid as $row) {
            $date = substr((string) $row->work_date, 0, 10);
            $this->priorDay[$row->employee_id][$date] = ($this->priorDay[$row->employee_id][$date] ?? 0) + (int) $row->minutes;
            if ($date >= $from) {
                $this->priorPeriod[$row->employee_id] = ($this->priorPeriod[$row->employee_id] ?? 0) + (int) $row->minutes;
            }
        }
    }

    public function linesFor(Employee $employee, PayrollPeriod $period, EarningContext $context): array
    {
        $requests = $this->candidates->get($employee->id, collect());
        if ($requests->isEmpty()) {
            return [];
        }

        $from = $period->start_date->toDateString();
        $dayMinutes = $this->priorDay[$employee->id] ?? [];
        $periodMinutes = $this->priorPeriod[$employee->id] ?? 0;
        $groups = [];

        foreach ($requests as $request) {
            $date = $request->work_date->toDateString();
            $check = $this->eligibility->evaluate($request);
            if ($check['eligible'] <= 0) {
                $this->exclude($request, $check['status'], $check);

                continue;
            }

            $rule = $this->rules->resolve($employee, $date);
            if (! $rule) {
                $this->exclude($request, 'NO_RULE', $check);

                continue;
            }
            $config = OvertimeRuleConfig::fromModel($rule);
            $basis = $config->rateBasis === 'FIXED_EARNINGS' ? $context->fixedEarnings() : $context->basic();
            if (Money::cmp($basis, '0') <= 0) {
                $this->exclude($request, 'NO_RATE_BASIS', $check);

                continue;
            }
            $rate = $this->calculator->hourlyRate($basis, $config);

            $calc = $this->calculator->calculate(
                $check['eligible'], $check['day_type'], $rate, $config, $context->decimals,
                $dayMinutes[$date] ?? 0, $periodMinutes,
            );
            if ($calc->payableMinutes <= 0) {
                $this->exclude($request, $calc->notes[0] ?? 'NOT_PAYABLE', $check);

                continue;
            }
            $dayMinutes[$date] = ($dayMinutes[$date] ?? 0) + $calc->payableMinutes;
            $periodMinutes += $calc->payableMinutes;
            $this->included++;

            $carried = $date < $from;
            $inclusion = [
                'request_id' => $request->id,
                'approved_minutes' => $check['approved'],
                'verified_minutes' => $check['verified'],
                'verification_status' => $check['status'],
                'day_type' => $check['day_type'],
                'rule_id' => $rule->id,
                'rate' => $rate,
                'minutes' => $calc->payableMinutes,
                'amount' => $calc->amount,
                'multiplier' => $calc->multiplier(),
                'snapshot' => [
                    'rule' => $config->toSnapshot(),
                    'rate_basis_amount' => $basis,
                    'hourly_rate' => $rate,
                    'work_date' => $date,
                    'requested_minutes' => $request->requested_minutes,
                    'approved_minutes' => $check['approved'],
                    'verified_minutes' => $check['verified'],
                    'verification_status' => $check['status'],
                    'eligible_minutes' => $check['eligible'],
                    'rounded_minutes' => $calc->roundedMinutes,
                    'payable_minutes' => $calc->payableMinutes,
                    'notes' => $calc->notes,
                    'segments' => $calc->segments,
                    'carried_over' => $carried,
                ],
            ];

            foreach ($calc->segments as $segment) {
                $key = $check['day_type'].'|'.$segment['multiplier'].'|'.$rate;
                $groups[$key] ??= ['day_type' => $check['day_type'], 'multiplier' => $segment['multiplier'], 'rate' => $rate, 'basis' => $basis, 'minutes' => 0, 'amount' => '0', 'refs' => [], 'payload' => []];
                $groups[$key]['minutes'] += $segment['minutes'];
                $groups[$key]['amount'] = Money::add($groups[$key]['amount'], $segment['amount']);
                $groups[$key]['refs'][] = [
                    'type' => 'overtime_request', 'id' => $request->id, 'work_date' => $date, 'day_type' => $check['day_type'],
                    'minutes' => $segment['minutes'], 'multiplier' => $segment['multiplier'], 'amount' => $segment['amount'],
                    'approved_minutes' => $check['approved'], 'verified_minutes' => $check['verified'], 'carried_over' => $carried,
                ];
                $groups[$key]['payload'][] = $inclusion + ['segment' => $segment];
            }
        }

        $lines = [];
        $i = 0;
        foreach ($groups as $g) {
            $hours = Money::round(Money::div((string) $g['minutes'], '60'), 2);
            $label = self::DAY_LABELS[$g['day_type']] ?? $g['day_type'];
            $lines[] = new EarningLine(
                sourceKey: self::KEY,
                code: 'OVERTIME',
                name: "Overtime – {$label} ×{$g['multiplier']}",
                amount: Money::round($g['amount'], $context->decimals),
                refs: $g['refs'],
                quantity: $hours,
                unitAmount: $g['rate'],
                baseAmount: $g['basis'],
                minutes: $g['minutes'],
                multiplier: $g['multiplier'],
                description: sprintf('%s h (%d min) × %s/h × %s', $hours, $g['minutes'], Money::format($g['rate'], 2), $g['multiplier']),
                sortOrder: 400 + $i++,
                payload: ['inclusions' => $g['payload']],
            );
        }

        return $lines;
    }

    public function summary(): array
    {
        return ['included_requests' => $this->included, 'excluded' => $this->excluded];
    }

    public function lockSources(PayrollRun $run, array $lines): void
    {
        $expected = [];
        foreach ($lines as $line) {
            foreach ($line->payload['inclusions'] ?? [] as $p) {
                $expected[$p['request_id']] = $p['approved_minutes'];
            }
        }
        if ($expected === []) {
            return;
        }
        ksort($expected);

        $locked = OvertimeRequest::query()->whereIn('id', array_keys($expected))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $taken = OvertimePayrollInclusion::query()->whereIn('active_request_id', array_keys($expected))
            ->where('payroll_run_id', '!=', $run->id)->pluck('overtime_request_id')->all();

        foreach ($expected as $id => $approved) {
            $row = $locked->get($id);
            if (! $row || $row->status !== RequestStatus::Approved || (int) $row->approved_minutes !== (int) $approved
                || in_array($row->payroll_status, [OvertimeRequest::PAYROLL_ADJUSTED, OvertimeRequest::PAYROLL_LEGACY], true)
                || in_array($id, $taken, false)) {
                throw new EarningSourceConflict("Overtime request {$id} changed or was included elsewhere during generation.");
            }
        }
    }

    public function markIncluded(PayrollRun $run, PayrollItemLine $line, EarningLine $earning): void
    {
        foreach ($earning->payload['inclusions'] ?? [] as $p) {
            $segment = $p['segment'] + ['payroll_item_line_id' => $line->id];
            $existing = OvertimePayrollInclusion::query()->where('active_request_id', $p['request_id'])->where('payroll_run_id', $run->id)->first();

            if ($existing) {
                $existing->fill(['segments' => array_merge($existing->segments ?? [], [$segment])])->save();

                continue;
            }

            try {
                OvertimePayrollInclusion::query()->create([
                    'overtime_request_id' => $p['request_id'],
                    'active_request_id' => $p['request_id'],
                    'employee_id' => $line->item()->value('employee_id'),
                    'payroll_run_id' => $run->id,
                    'payroll_item_line_id' => $line->id,
                    'overtime_rule_id' => $p['rule_id'],
                    'minutes' => $p['minutes'],
                    'day_type' => $p['day_type'],
                    'multiplier' => $p['multiplier'],
                    'rate' => $p['rate'],
                    'amount' => $p['amount'],
                    'segments' => [$segment],
                    'snapshot' => $p['snapshot'],
                ]);
            } catch (UniqueConstraintViolationException $e) {
                throw new EarningSourceConflict("Overtime request {$p['request_id']} is already included in another payroll.", 0, $e);
            }

            OvertimeRequest::query()->whereKey($p['request_id'])->update([
                'payroll_status' => OvertimeRequest::PAYROLL_INCLUDED,
                'verified_minutes' => $p['verified_minutes'],
                'verification_status' => $p['verification_status'],
                'verified_at' => now(),
                'day_type' => $p['day_type'],
                'updated_at' => now(),
            ]);
        }
    }

    public function releaseRun(PayrollRun $run): int
    {
        $live = OvertimePayrollInclusion::query()->where('payroll_run_id', $run->id)->whereNotNull('active_request_id')->whereNull('locked_at');
        $ids = (clone $live)->pluck('overtime_request_id')->all();
        if ($ids === []) {
            return 0;
        }
        (clone $live)->delete();
        OvertimeRequest::query()->whereIn('id', $ids)->where('payroll_status', OvertimeRequest::PAYROLL_INCLUDED)
            ->update(['payroll_status' => OvertimeRequest::PAYROLL_PENDING, 'updated_at' => now()]);

        return count($ids);
    }

    public function freezeRun(PayrollRun $run): void
    {
        OvertimePayrollInclusion::query()->where('payroll_run_id', $run->id)->whereNotNull('active_request_id')
            ->whereNull('locked_at')->update(['locked_at' => now(), 'updated_at' => now()]);
    }

    /** @param array<string, mixed> $check */
    private function exclude(OvertimeRequest $request, string $reason, array $check): void
    {
        $this->excluded[] = [
            'overtime_request_id' => $request->id,
            'employee_id' => $request->employee_id,
            'work_date' => $request->work_date->toDateString(),
            'reason' => $reason,
            'approved_minutes' => $check['approved'] ?? null,
            'verified_minutes' => $check['verified'] ?? null,
        ];
    }
}
