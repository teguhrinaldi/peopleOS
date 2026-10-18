<?php

namespace App\Services\Payroll;

use App\Enums\PayrollPeriodType;
use App\Enums\PayrollStatus;
use App\Enums\SalaryComponentKind;
use App\Exceptions\ApiException;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Models\PayrollItem;
use App\Models\PayrollItemLine;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Creating periods (each with its single DRAFT run), adjustment periods and their manual entries.
 * Regular periods of a company never overlap (cancelled ones excluded); creation is serialised by locking
 * the company row.
 */
class PayrollPeriodService
{
    public const MAX_DAYS = 31;

    public function __construct(
        private readonly CompanyContext $context,
        private readonly PayrollTransitions $transitions,
        private readonly PayrollSettings $settings,
        private readonly ActivityLogger $activity,
    ) {}

    public function createRegular(User $actor, string $start, string $end, ?string $name = null, ?string $payDate = null, ?string $notes = null): PayrollPeriod
    {
        $this->transitions->requirePermission($actor, 'payroll.manage');
        $s = CarbonImmutable::parse($start)->startOfDay();
        $e = CarbonImmutable::parse($end)->startOfDay();
        if ($e->lt($s) || $s->diffInDays($e) + 1 > self::MAX_DAYS) {
            throw ApiException::make('INVALID_PERIOD', 'A payroll period must end on or after its start and last at most '.self::MAX_DAYS.' days.');
        }

        return DB::transaction(function () use ($actor, $s, $e, $name, $payDate, $notes) {
            Company::query()->whereKey($this->context->id())->lockForUpdate()->first();

            $overlap = PayrollPeriod::query()->where('company_id', $this->context->id())
                ->where('type', PayrollPeriodType::Regular->value)
                ->where('status', '!=', PayrollStatus::Cancelled->value)
                ->where('start_date', '<=', $e->toDateString())
                ->where('end_date', '>=', $s->toDateString())
                ->first();
            if ($overlap) {
                throw ApiException::make('PERIOD_OVERLAP', "This period overlaps {$overlap->name}.", 409, ['payroll_period_id' => $overlap->id]);
            }

            $fullMonth = $s->day === 1 && $e->isSameDay($s->endOfMonth()->startOfDay());
            $base = $fullMonth ? $s->format('Y-m') : $s->format('Ymd').'-'.$e->format('Ymd');

            $period = PayrollPeriod::query()->create([
                'company_id' => $this->context->id(),
                'type' => PayrollPeriodType::Regular,
                'code' => $this->uniqueCode($base),
                'name' => $name ?: ($fullMonth ? $s->format('F Y') : $s->format('d M Y').' – '.$e->format('d M Y')),
                'start_date' => $s->toDateString(),
                'end_date' => $e->toDateString(),
                'pay_date' => $payDate,
                'status' => PayrollStatus::Draft,
                'notes' => $notes,
                'created_by' => $actor->id,
            ]);
            $this->createRun($period);

            $this->activity->log('payroll_period.created', $period, null, $period->only(['code', 'name', 'type']) + [
                'start_date' => $period->start_date->toDateString(), 'end_date' => $period->end_date->toDateString(),
            ], null, $actor);

            return $period->load('run');
        });
    }

    /** A correction of a finalized/paid period. Same dates, its own run and workflow. */
    public function createAdjustment(User $actor, PayrollPeriod $original, string $reason, ?string $name = null, ?string $payDate = null): PayrollPeriod
    {
        $this->transitions->requirePermission($actor, 'payroll.manage');
        if (trim($reason) === '') {
            throw ApiException::make('REASON_REQUIRED', 'A reason is required for an adjustment.');
        }

        return DB::transaction(function () use ($actor, $original, $reason, $name, $payDate) {
            Company::query()->whereKey($this->context->id())->lockForUpdate()->first();
            $original = PayrollPeriod::query()->whereKey($original->id)->firstOrFail();

            if ($original->isAdjustment()) {
                throw ApiException::make('INVALID_ADJUSTMENT_TARGET', 'Adjust the original regular period, not an adjustment.', 422);
            }
            if (! $original->status->isLocked()) {
                throw ApiException::make('PERIOD_NOT_FINALIZED', 'Only finalized or paid payroll can be adjusted. Regenerate this one instead.', 409);
            }

            $n = PayrollPeriod::query()->where('adjusts_payroll_period_id', $original->id)->count() + 1;
            $period = PayrollPeriod::query()->create([
                'company_id' => $this->context->id(),
                'type' => PayrollPeriodType::Adjustment,
                'code' => $this->uniqueCode($original->code.'-A'.$n),
                'name' => $name ?: "{$original->name} – Adjustment {$n}",
                'start_date' => $original->start_date->toDateString(),
                'end_date' => $original->end_date->toDateString(),
                'pay_date' => $payDate,
                'adjusts_payroll_period_id' => $original->id,
                'adjustment_reason' => $reason,
                'status' => PayrollStatus::Draft,
                'created_by' => $actor->id,
            ]);
            $this->createRun($period, $original->run);

            $this->activity->log('payroll_adjustment.period_created', $period, null, [
                'code' => $period->code, 'adjusts' => $original->code, 'reason' => $reason,
            ], null, $actor);

            return $period->load('run');
        });
    }

    /** @param array{name?: string, pay_date?: ?string, notes?: ?string} $data */
    public function update(User $actor, PayrollPeriod $period, array $data): PayrollPeriod
    {
        $this->transitions->requirePermission($actor, 'payroll.manage');

        return DB::transaction(function () use ($actor, $period, $data) {
            [$p] = $this->transitions->lock($period);
            if ($p->status->isLocked() || $p->status === PayrollStatus::Cancelled) {
                throw ApiException::make('PAYROLL_LOCKED', 'This payroll period can no longer be edited.', 409);
            }
            $p->fill(array_intersect_key($data, array_flip(['name', 'pay_date', 'notes'])));
            [$old, $new] = $this->activity->diff($p);
            if ($new !== []) {
                $p->save();
                $this->activity->log('payroll_period.updated', $p, $old, $new, null, $actor);
            }

            return $p;
        });
    }

    public function addAdjustmentEntry(User $actor, PayrollPeriod $period, Employee $employee, SalaryComponentKind $kind, string $label, string $amount, string $reason): PayrollAdjustment
    {
        $this->transitions->requirePermission($actor, 'payroll.manage');
        if (Money::cmp(Money::of($amount), '0') <= 0) {
            throw ApiException::make('VALIDATION_ERROR', 'The given data was invalid.', 422, ['amount' => ['The amount must be greater than 0.']]);
        }

        return DB::transaction(function () use ($actor, $period, $employee, $kind, $label, $amount, $reason) {
            [$p, $run] = $this->lockDraftAdjustment($period);

            $inOriginal = PayrollItem::query()
                ->where('payroll_run_id', PayrollRun::query()->where('payroll_period_id', $p->adjusts_payroll_period_id)->value('id'))
                ->where('employee_id', $employee->id)->exists();
            if (! $inOriginal) {
                throw ApiException::make('EMPLOYEE_NOT_IN_ORIGINAL_PAYROLL', 'This employee was not paid in the payroll being adjusted.', 422);
            }

            $entry = PayrollAdjustment::query()->create([
                'payroll_period_id' => $p->id, 'employee_id' => $employee->id, 'kind' => $kind, 'label' => $label,
                'amount' => Money::round(Money::of($amount), 2), 'reason' => $reason, 'created_by' => $actor->id,
            ]);
            $this->invalidate($run);

            $this->activity->log('payroll_adjustment.entry_added', $entry, null, [
                'period' => $p->code, 'employee_id' => $employee->id, 'kind' => $kind->value, 'label' => $label,
                'amount' => (string) $entry->amount, 'reason' => $reason,
            ], null, $actor);

            return $entry;
        });
    }

    public function removeAdjustmentEntry(User $actor, PayrollPeriod $period, PayrollAdjustment $entry): void
    {
        $this->transitions->requirePermission($actor, 'payroll.manage');

        DB::transaction(function () use ($actor, $period, $entry) {
            [$p, $run] = $this->lockDraftAdjustment($period);
            if ($entry->payroll_period_id !== $p->id) {
                throw ApiException::make('NOT_FOUND', 'The requested resource was not found.', 404);
            }
            $this->invalidate($run);
            $this->activity->log('payroll_adjustment.entry_removed', $entry, [
                'period' => $p->code, 'employee_id' => $entry->employee_id, 'label' => $entry->label, 'amount' => (string) $entry->amount,
            ], null, null, $actor);
            $entry->delete();
        });
    }

    /** @return array{0: PayrollPeriod, 1: PayrollRun} */
    private function lockDraftAdjustment(PayrollPeriod $period): array
    {
        [$p, $run] = $this->transitions->lock($period);
        if (! $p->isAdjustment()) {
            throw ApiException::make('NOT_AN_ADJUSTMENT', 'Manual entries can only be added to adjustment periods.', 422);
        }
        if ($run->status !== PayrollStatus::Draft) {
            throw ApiException::make('INVALID_STATE_TRANSITION', "Adjustment entries can only change while the payroll is DRAFT (it is {$run->status->value}).", 409);
        }

        return [$p, $run];
    }

    /** Entries changed: generated items are stale, so they are dropped and generation is required again. */
    private function invalidate(PayrollRun $run): void
    {
        PayrollItemLine::query()->whereIn('payroll_item_id', PayrollItem::query()->select('id')->where('payroll_run_id', $run->id))->delete();
        PayrollItem::query()->where('payroll_run_id', $run->id)->delete();
        $run->fill([
            'generated_at' => null, 'generated_by' => null, 'employee_count' => 0, 'total_gross' => 0,
            'total_deductions' => 0, 'total_net' => 0, 'negative_net_count' => 0, 'summary' => null,
        ])->save();
    }

    private function createRun(PayrollPeriod $period, ?PayrollRun $original = null): PayrollRun
    {
        $currency = $original?->currency ?? $this->settings->currency();

        return PayrollRun::query()->create([
            'payroll_period_id' => $period->id,
            'status' => PayrollStatus::Draft,
            'currency' => $currency,
            'decimals' => $original?->decimals ?? $this->settings->decimals($currency),
        ]);
    }

    private function uniqueCode(string $base): string
    {
        $code = $base;
        for ($i = 2; PayrollPeriod::query()->where('company_id', $this->context->id())->where('code', $code)->exists(); $i++) {
            $code = "{$base}-R{$i}";
        }

        return $code;
    }
}
