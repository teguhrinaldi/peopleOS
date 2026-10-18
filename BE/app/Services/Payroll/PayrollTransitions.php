<?php

namespace App\Services\Payroll;

use App\Enums\PayrollStatus;
use App\Exceptions\ApiException;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of payroll_runs.status and its mirror payroll_periods.status.
 * Callers must already hold the row locks (lock()) inside their transaction.
 */
class PayrollTransitions
{
    /**
     * Lock the period, then its run (always in this order, so concurrent requests cannot deadlock).
     *
     * @return array{0: PayrollPeriod, 1: PayrollRun}
     */
    public function lock(PayrollPeriod|int $period): array
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Payroll rows must be locked inside a transaction.');
        }

        $id = $period instanceof PayrollPeriod ? $period->id : $period;
        $lockedPeriod = PayrollPeriod::query()->whereKey($id)->lockForUpdate()->firstOrFail();
        $run = PayrollRun::query()->where('payroll_period_id', $id)->lockForUpdate()->firstOrFail();

        return [$lockedPeriod, $run];
    }

    /**
     * Run a state-machine action on a locked period+run and persist the new state on both.
     *
     * @param  array<string, mixed>  $attributes  other run columns written together with the status
     */
    public function apply(PayrollPeriod $period, PayrollRun $run, string $action, array $attributes = [], ?PayrollStatus $restoreTo = null): PayrollStatus
    {
        $to = PayrollStateMachine::transition($action, $run->status, $restoreTo);

        $run->fill($attributes);
        $run->status = $to;
        $run->save();

        $period->status = $to;
        $period->save();

        return $to;
    }

    public function requirePermission(User $user, string $permission): void
    {
        if (! $user->hasPermission($permission)) {
            throw ApiException::make('FORBIDDEN', 'You do not have permission to perform this action.', 403, ['permission' => $permission]);
        }
    }
}
