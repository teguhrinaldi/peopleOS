<?php

namespace App\Services\Leave;

use App\Exceptions\ApiException;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\ActivityLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Creates balances lazily (one per employee/type/leave year) and applies HR adjustments.
 * The allowance is snapshotted when the balance is created; later policy edits affect new years only.
 */
class LeaveBalanceService
{
    public function __construct(
        private readonly LeaveCalendar $calendar,
        private readonly ActivityLogger $activity,
    ) {}

    public function policyFor(Employee $employee, LeaveType $type): LeavePolicy
    {
        $policies = $type->policies()->get();
        $employmentType = $employee->employment_type?->value;

        return $policies->firstWhere('employment_type', $employmentType)
            ?? $policies->firstWhere('employment_type', null)
            ?? throw ApiException::make('LEAVE_POLICY_MISSING', "Leave type {$type->name} has no policy for this employee.", 422);
    }

    /** Balance row for the year, created if missing. Pass $lock inside a transaction to hold it FOR UPDATE. */
    public function balanceFor(Employee $employee, LeaveType $type, int $year, bool $lock = false): LeaveBalance
    {
        $find = fn () => LeaveBalance::query()
            ->where(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $year])
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->first();

        if ($balance = $find()) {
            return $balance;
        }

        $policy = $this->policyFor($employee, $type);

        try {
            LeaveBalance::query()->create([
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'year' => $year,
                'allowance' => $this->allowanceFor($employee, $policy, $year),
                'carried_over' => $this->carryOverFor($employee, $type, $policy, $year),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Created concurrently by another request; fall through and read it.
        }

        return $find() ?? throw new \RuntimeException('Leave balance could not be created.');
    }

    public function adjust(LeaveBalance $balance, string $delta, string $reason, User $actor): LeaveBalance
    {
        if (bccomp($delta, '0', 1) === 0) {
            throw ApiException::make('VALIDATION_ERROR', 'The adjustment cannot be zero.');
        }

        $locked = LeaveBalance::query()->whereKey($balance->id)->lockForUpdate()->firstOrFail();
        $before = (string) $locked->adjustment;
        $locked->adjustment = bcadd($before, $delta, 1);

        $available = $locked->available();
        if ($available !== null && bccomp($available, '0', 1) < 0) {
            throw ApiException::make('LEAVE_INSUFFICIENT_BALANCE', 'The adjustment would make the balance negative.', 422, ['available' => $available]);
        }

        $locked->save();
        $this->activity->log('leave_balance.adjusted', $locked, ['adjustment' => $before], [
            'adjustment' => (string) $locked->adjustment, 'delta' => $delta, 'reason' => $reason,
        ], actor: $actor);

        return $locked;
    }

    /** Pro-rated by whole remaining months in the employee's first leave year, rounded down to half days. */
    private function allowanceFor(Employee $employee, LeavePolicy $policy, int $year): ?string
    {
        if ($policy->isUnlimited()) {
            return null;
        }

        $allowance = (string) $policy->annual_allowance;
        [$yearStart] = $this->calendar->yearBounds($year);
        $join = CarbonImmutable::parse($employee->join_date);

        if ($policy->prorate_first_year && $join->greaterThan(CarbonImmutable::parse($yearStart))) {
            $monthsLeft = 12 - (int) CarbonImmutable::parse($yearStart)->diffInMonths($join->startOfMonth());
            $prorated = bcdiv(bcmul($allowance, (string) max(0, $monthsLeft), 2), '12', 2);
            $allowance = bcdiv((string) floor((float) bcmul($prorated, '2', 2)), '2', 1);
        }

        return $allowance;
    }

    private function carryOverFor(Employee $employee, LeaveType $type, LeavePolicy $policy, int $year): string
    {
        if (bccomp((string) $policy->carry_over_max, '0', 1) <= 0) {
            return '0.0';
        }

        $previous = LeaveBalance::query()
            ->where(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $year - 1])
            ->first();
        $left = $previous?->available();

        if ($left === null || bccomp($left, '0', 1) <= 0) {
            return '0.0';
        }

        return bccomp($left, (string) $policy->carry_over_max, 1) > 0 ? (string) $policy->carry_over_max : $left;
    }
}
