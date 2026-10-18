<?php

namespace App\Services\Payroll;

use App\Enums\PayrollStatus;
use App\Exceptions\ApiException;
use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Models\PayrollItem;
use App\Models\PayrollItemLine;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Overtime\OvertimeEarningSource;
use App\Services\Payroll\Calculation\CalculatedLine;
use App\Services\Payroll\Calculation\ComponentValue;
use App\Services\Payroll\Calculation\ManualEntry;
use App\Services\Payroll\Calculation\PayrollCalculator;
use App\Services\Payroll\Calculation\PayrollInput;
use App\Services\Payroll\Contracts\PayrollEarningSource;
use App\Services\Payroll\Earnings\EarningContext;
use App\Services\Payroll\Earnings\EarningLine;
use App\Services\Payroll\Earnings\EarningSourceConflict;
use App\Services\Payroll\Earnings\EarningSources;
use App\Support\CompanyContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Generation = (re)building every payroll item of a run from current data, idempotently.
 *
 *  1. tx: lock period+run, DRAFT|REVIEW -> PROCESSING with a private token      (a second caller now gets 409 PAYROLL_PROCESSING)
 *  2. no tx: collect inputs and calculate every employee in memory               (pure PayrollCalculator)
 *  3. tx: lock again, verify our token, replace ALL items/lines atomically, totals = SUM(items), PROCESSING -> DRAFT
 *  on failure: PROCESSING -> the state it started from; the previous items are untouched because step 3 is atomic.
 *
 * Unique (payroll_run_id, employee_id) makes a duplicate item impossible even if the above were bypassed.
 * A PROCESSING run older than config('payroll.processing_timeout_minutes') is treated as crashed and taken over.
 */
class PayrollGenerator
{
    public function __construct(
        private readonly PayrollTransitions $transitions,
        private readonly PayrollInputCollector $collector,
        private readonly PayrollCalculator $calculator,
        private readonly PayrollCalendar $calendar,
        private readonly PayrollSettings $settings,
        private readonly ActivityLogger $activity,
        private readonly CompanyContext $context,
        private readonly EarningSources $sources,
    ) {}

    public function generate(User $actor, PayrollPeriod $period): PayrollRun
    {
        $this->transitions->requirePermission($actor, 'payroll.manage');
        $token = Str::random(40);

        $startedFrom = DB::transaction(function () use ($actor, $period, $token) {
            [$lockedPeriod, $run] = $this->transitions->lock($period);

            if ($run->status === PayrollStatus::Processing) {
                if (! $this->isStale($run)) {
                    throw ApiException::make('PAYROLL_PROCESSING', 'This payroll is being generated right now. Try again in a moment.', 409, [
                        'started_at' => $run->processing_started_at?->toIso8601String(),
                        'started_by' => $run->processing_by,
                    ]);
                }
                $previous = PayrollStatus::tryFrom((string) $run->processing_from) ?? PayrollStatus::Draft;
                $this->transitions->apply($lockedPeriod, $run, 'abort_generation', $this->clearProcessing(), $previous);
                $this->activity->log('payroll.generation_released', $run, null, ['period' => $lockedPeriod->code], 'Stalled generation taken over', $actor);
            }

            $from = $run->status;
            $this->transitions->apply($lockedPeriod, $run, 'generate', [
                'processing_token' => $token,
                'processing_from' => $from->value,
                'processing_started_at' => now(),
                'processing_by' => $actor->id,
            ]);

            return $from;
        });

        // A source record (e.g. an overtime request) that changed or was taken by another run between the build
        // and the store step makes the store roll back; the build is then simply repeated (max 3 attempts).
        for ($attempt = 1; ; $attempt++) {
            try {
                $built = $period->isAdjustment() ? $this->buildAdjustment($period) : $this->buildRegular($period);

                return DB::transaction(fn () => $this->store($actor, $period, $token, $built, $startedFrom));
            } catch (EarningSourceConflict|QueryException $e) {
                if ($e instanceof QueryException && ! in_array((string) $e->getCode(), ['40001', '1213'], true) && ! str_contains($e->getMessage(), 'Deadlock')) {
                    $this->release($actor, $period, $token, $startedFrom, $e);
                    throw $e;
                }
                if ($attempt < 3) {
                    continue;
                }
                $error = ApiException::make('PAYROLL_INPUT_CHANGED', 'Payroll inputs kept changing during generation. Try again.', 409);
                $this->release($actor, $period, $token, $startedFrom, $error);
                throw $error;
            } catch (Throwable $e) {
                $this->release($actor, $period, $token, $startedFrom, $e);
                throw $e;
            }
        }
    }

    /** @return array<string, mixed> */
    private function buildRegular(PayrollPeriod $period): array
    {
        $currency = $this->settings->currency();
        $decimals = $this->settings->decimals($currency);
        $workingDays = $this->calendar->workingDays($period->start_date->toDateString(), $period->end_date->toDateString());

        ['employees' => $employees, 'skipped' => $skipped] = $this->collector->eligible($period);
        $collected = $this->collector->collect($period, $employees, $decimals, $workingDays);

        $run = $this->runOf($period);
        $sources = $this->prepareSources($period, $run, $employees->pluck('id')->all());
        $superseded = $this->overtimeEngineOn($sources);

        $items = [];
        foreach ($collected as $c) {
            if ($c->input->components === []) {
                $skipped[] = $this->collector->skip($c->employee, 'NO_SALARY_COMPONENTS');

                continue;
            }
            $input = $this->withSources($c->input, $this->sourceLines($sources, $c->employee, $period, $run, $c->input->components, $decimals, $c->referenceDate), $superseded);
            $items[] = [
                'employee_id' => $c->employee->id,
                'adjusts_payroll_item_id' => null,
                'snapshot' => $c->snapshot,
                'reference_date' => $c->referenceDate,
                'input' => $input,
                'result' => $this->calculator->calculate($input),
            ];
        }

        return compact('currency', 'decimals', 'workingDays', 'items', 'skipped', 'sources') + ['eligible' => $employees->count()];
    }

    private function runOf(PayrollPeriod $period): PayrollRun
    {
        return PayrollRun::query()->where('payroll_period_id', $period->id)->firstOrFail();
    }

    /** @param list<int> $employeeIds @return list<PayrollEarningSource> */
    private function prepareSources(PayrollPeriod $period, PayrollRun $run, array $employeeIds): array
    {
        $sources = $this->sources->enabled();
        foreach ($sources as $source) {
            $source->prepare($period, $run, $employeeIds);
        }

        return $sources;
    }

    /** @param list<PayrollEarningSource> $sources */
    private function overtimeEngineOn(array $sources): bool
    {
        foreach ($sources as $source) {
            if ($source->key() === OvertimeEarningSource::KEY) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<PayrollEarningSource>  $sources
     * @param  list<ComponentValue>  $components
     * @return list<EarningLine>
     */
    private function sourceLines(array $sources, Employee $employee, PayrollPeriod $period, PayrollRun $run, array $components, int $decimals, ?string $referenceDate): array
    {
        $context = new EarningContext($run, $components, $decimals, (string) $referenceDate);
        $lines = [];
        foreach ($sources as $source) {
            array_push($lines, ...$source->linesFor($employee, $period, $context));
        }

        return $lines;
    }

    /** @param list<EarningLine> $lines */
    private function withSources(PayrollInput $in, array $lines, bool $overtimeSuperseded): PayrollInput
    {
        return new PayrollInput(
            components: $in->components,
            decimals: $in->decimals,
            workingDays: $in->workingDays,
            presentDays: $in->presentDays,
            overtimeMinutes: $overtimeSuperseded ? 0 : $in->overtimeMinutes,
            unpaidLeaveDays: $in->unpaidLeaveDays,
            manualEntries: $in->manualEntries,
            sourceLines: $lines,
            overtimeComponentSuperseded: $overtimeSuperseded,
        );
    }

    /** Adjustment periods: one item per employee with entries; lines = the manual entries. @return array<string, mixed> */
    private function buildAdjustment(PayrollPeriod $period): array
    {
        $original = PayrollRun::query()->where('payroll_period_id', $period->adjusts_payroll_period_id)->firstOrFail();
        $currency = $original->currency;
        $decimals = $original->decimals;

        $entries = PayrollAdjustment::query()->where('payroll_period_id', $period->id)->orderBy('id')->get()->groupBy('employee_id');
        $originalItems = PayrollItem::query()->where('payroll_run_id', $original->id)->get()->keyBy('employee_id');

        // Earning sources (e.g. overtime approved after the original was finalized) for people paid in the original.
        $run = $this->runOf($period);
        $sources = $this->prepareSources($period, $run, $originalItems->keys()->all());
        $sourced = [];
        if ($sources !== []) {
            $employees = Employee::withTrashed()->whereIn('id', $originalItems->keys())->get();
            foreach ($this->collector->collect($period, $employees, $decimals, 0) as $employeeId => $c) {
                $lines = $this->sourceLines($sources, $c->employee, $period, $run, $c->input->components, $decimals, $originalItems->get($employeeId)?->salary_reference_date?->toDateString() ?? $c->referenceDate);
                if ($lines !== []) {
                    $sourced[$employeeId] = $lines;
                }
            }
        }
        $employeeIds = collect($entries->keys())->merge(array_keys($sourced))->unique()->sort()->values();

        $items = [];
        $skipped = [];
        foreach ($employeeIds as $employeeId) {
            $rows = $entries->get($employeeId, collect());
            $source = $originalItems->get($employeeId);
            if (! $source) {
                $skipped[] = ['employee_id' => $employeeId, 'reason' => 'NOT_IN_ORIGINAL_PAYROLL'];

                continue;
            }
            $input = new PayrollInput(
                components: [],
                decimals: $decimals,
                manualEntries: $rows->map(fn (PayrollAdjustment $a) => new ManualEntry($a->kind, $a->label, (string) $a->amount, $a->reason, $a->id))->all(),
                sourceLines: $sourced[$employeeId] ?? [],
            );
            $items[] = [
                'employee_id' => (int) $employeeId,
                'adjusts_payroll_item_id' => $source->id,
                'snapshot' => $source->only([
                    'employee_number', 'employee_name', 'department_name', 'position_name', 'branch_name', 'employment_type',
                ]) + ['join_date' => $source->join_date?->toDateString(), 'terminated_at' => $source->terminated_at?->toDateString()],
                'reference_date' => $source->salary_reference_date?->toDateString(),
                'input' => $input,
                'result' => $this->calculator->calculate($input),
            ];
        }

        return compact('currency', 'decimals', 'items', 'skipped', 'sources') + ['workingDays' => 0, 'eligible' => $employeeIds->count()];
    }

    /** @param array<string, mixed> $built */
    private function store(User $actor, PayrollPeriod $period, string $token, array $built, PayrollStatus $startedFrom): PayrollRun
    {
        [$lockedPeriod, $run] = $this->transitions->lock($period);
        if ($run->status !== PayrollStatus::Processing || ! hash_equals((string) $run->processing_token, $token)) {
            throw ApiException::make('PAYROLL_PROCESSING', 'Another generation took over this payroll.', 409);
        }

        $regenerated = PayrollItem::query()->where('payroll_run_id', $run->id)->exists();

        // Earning sources: forget what this run included before, then lock + re-validate what it will include now.
        foreach ($this->sources->all() as $source) {
            $source->releaseRun($run);
        }
        $byKey = [];
        foreach ($built['sources'] as $source) {
            $byKey[$source->key()] = $source;
            $lines = [];
            foreach ($built['items'] as $row) {
                foreach ($row['input']->sourceLines as $l) {
                    if ($l->sourceKey === $source->key()) {
                        $lines[] = $l;
                    }
                }
            }
            $source->lockSources($run, $lines);
        }

        PayrollItemLine::query()->whereIn('payroll_item_id', PayrollItem::query()->select('id')->where('payroll_run_id', $run->id))->delete();
        PayrollItem::query()->where('payroll_run_id', $run->id)->delete();

        $warnings = [];
        $now = now();
        foreach ($built['items'] as $row) {
            /** @var PayrollInput $input */
            $input = $row['input'];
            $result = $row['result'];

            $item = PayrollItem::query()->create($row['snapshot'] + [
                'payroll_run_id' => $run->id,
                'employee_id' => $row['employee_id'],
                'adjusts_payroll_item_id' => $row['adjusts_payroll_item_id'],
                'salary_reference_date' => $row['reference_date'],
                'working_days' => $input->workingDays,
                'present_days' => $input->presentDays,
                'overtime_minutes' => $input->overtimeMinutes + array_sum(array_map(fn (EarningLine $l) => $l->sourceKey === OvertimeEarningSource::KEY ? (int) $l->minutes : 0, $input->sourceLines)),
                'unpaid_leave_days' => $input->unpaidLeaveDays,
                'basic_salary' => $result->basic,
                'gross_pay' => $result->gross,
                'total_deductions' => $result->deductions,
                'net_pay' => $result->net,
                'has_negative_net' => $result->isNegative(),
                'warnings' => $result->warnings ?: null,
            ]);

            $plain = array_filter($result->lines, fn (CalculatedLine $l) => $l->source === null);
            $lines = array_map(fn (CalculatedLine $l) => $l->toRow() + [
                'payroll_item_id' => $item->id, 'created_at' => $now, 'updated_at' => $now,
            ], array_values($plain));
            if ($lines !== []) {
                PayrollItemLine::query()->insert($lines);
            }
            foreach ($result->lines as $l) {
                if ($l->source !== null) {
                    $stored = PayrollItemLine::query()->create(['source_refs' => $l->source->refs] + $l->toRow() + ['payroll_item_id' => $item->id]);
                    $byKey[$l->source->sourceKey]->markIncluded($run, $stored, $l->source);
                }
            }

            if ($result->warnings !== []) {
                $warnings[] = [
                    'employee_id' => $row['employee_id'],
                    'employee_number' => $row['snapshot']['employee_number'],
                    'employee_name' => $row['snapshot']['employee_name'],
                    'codes' => $result->warnings,
                ];
            }
        }

        $totals = PayrollItem::query()->where('payroll_run_id', $run->id)->selectRaw(
            'COUNT(*) AS employees, COALESCE(SUM(gross_pay), 0) AS gross, COALESCE(SUM(total_deductions), 0) AS deductions, '
            .'COALESCE(SUM(net_pay), 0) AS net, COALESCE(SUM(has_negative_net), 0) AS negatives'
        )->first();

        $company = $this->context->company();
        $this->transitions->apply($lockedPeriod, $run, 'finish_generation', $this->clearProcessing() + [
            'currency' => $built['currency'],
            'decimals' => $built['decimals'],
            'company_snapshot' => [
                'name' => $company->name, 'legal_name' => $company->legal_name, 'address' => $company->address,
                'email' => $company->email, 'phone' => $company->phone,
            ],
            'summary' => [
                'working_days' => $built['workingDays'],
                'eligible' => $built['eligible'],
                'included' => (int) $totals->employees,
                'skipped' => $built['skipped'],
                'warnings' => $warnings,
                'earning_sources' => collect($built['sources'])->mapWithKeys(fn (PayrollEarningSource $s) => [$s->key() => $s->summary()])->all(),
            ],
            'employee_count' => (int) $totals->employees,
            'total_gross' => (string) $totals->gross,
            'total_deductions' => (string) $totals->deductions,
            'total_net' => (string) $totals->net,
            'negative_net_count' => (int) $totals->negatives,
            'generated_by' => $actor->id,
            'generated_at' => now(),
            'submitted_by' => null,
            'submitted_at' => null,
            'approved_by' => null,
            'approved_at' => null,
        ]);

        $this->activity->log($regenerated ? 'payroll.regenerated' : 'payroll.generated', $run, ['status' => $startedFrom->value], [
            'period' => $lockedPeriod->code,
            'status' => PayrollStatus::Draft->value,
            'employees' => (int) $totals->employees,
            'skipped' => count($built['skipped']),
            'negative_net' => (int) $totals->negatives,
        ], null, $actor);

        return $run->refresh();
    }

    private function release(User $actor, PayrollPeriod $period, string $token, PayrollStatus $startedFrom, Throwable $cause): void
    {
        try {
            DB::transaction(function () use ($actor, $period, $token, $startedFrom, $cause) {
                [$lockedPeriod, $run] = $this->transitions->lock($period);
                if ($run->status === PayrollStatus::Processing && hash_equals((string) $run->processing_token, $token)) {
                    $this->transitions->apply($lockedPeriod, $run, 'abort_generation', $this->clearProcessing(), $startedFrom);
                    $this->activity->log('payroll.generation_failed', $run, null, [
                        'period' => $lockedPeriod->code,
                        'error' => $cause instanceof ApiException ? $cause->errorCode : class_basename($cause),
                    ], null, $actor);
                }
            });
        } catch (Throwable) {
            // The stale-processing timeout recovers the run if even the release fails.
        }
    }

    private function isStale(PayrollRun $run): bool
    {
        $timeout = max(1, (int) config('payroll.processing_timeout_minutes', 15));

        return $run->processing_started_at === null || $run->processing_started_at->lt(now()->subMinutes($timeout));
    }

    /** @return array<string, null> */
    private function clearProcessing(): array
    {
        return ['processing_token' => null, 'processing_from' => null, 'processing_started_at' => null, 'processing_by' => null];
    }
}
