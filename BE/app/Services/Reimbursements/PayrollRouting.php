<?php

namespace App\Services\Reimbursements;

use App\Enums\ExpenseStatus as S;
use App\Exceptions\ApiException;
use App\Models\Employee;
use App\Models\ExpensePayment;
use App\Models\ExpenseRequest;
use App\Models\ExpenseRequestEvent;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Notifications\NotificationService;
use App\Support\CompanyContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Hook for the payroll generator (NOT wired into payroll yet; the coordinator connects it).
 *
 *   eligibleForPayroll(employee, start, end)  -> approved claims routed to PAYROLL that no payroll line carries yet
 *   markIncluded(request, lineId, periodId)   -> link the claim to the earning line, exactly once
 *   settlePeriod(periodId, actor)             -> when the period is paid, flip its claims to PAID (method PAYROLL)
 *
 * A claim routed to SEPARATE is never returned here, and a claim that was paid separately can never be included.
 */
class PayrollRouting
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly ActivityLogger $activity,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Claims approved on or before the end of the period and not yet linked to any payroll line. $start is accepted for
     * symmetry with the generator but deliberately NOT used as a lower bound, so a claim approved after the previous cut-off
     * is picked up by the next run instead of being skipped.
     *
     * @return Collection<int, ExpenseRequest>
     */
    public function eligibleForPayroll(Employee $employee, string $start, string $end): Collection
    {
        $cutoff = $this->context->localToUtc($end, '23:59:59');

        return ExpenseRequest::query()
            ->where('company_id', $this->context->id())
            ->where('employee_id', $employee->id)
            ->where('payment_route', 'PAYROLL')
            ->whereIn('status', [S::Approved->value, S::ScheduledForPayment->value])
            ->whereNull('payroll_item_line_id')
            ->whereDoesntHave('payment')
            ->where('reviewed_at', '<=', $cutoff)
            ->with('category')
            ->orderBy('reviewed_at')->orderBy('id')
            ->get();
    }

    /** Idempotent for the same line; refuses another line, a separately paid claim and a SEPARATE-route claim. */
    public function markIncluded(ExpenseRequest $request, int $payrollItemLineId, int $payrollPeriodId): ExpenseRequest
    {
        return DB::transaction(function () use ($request, $payrollItemLineId, $payrollPeriodId) {
            $locked = ExpenseRequest::query()->where('company_id', $this->context->id())->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($locked->payroll_item_line_id !== null) {
                if ((int) $locked->payroll_item_line_id === $payrollItemLineId && (int) $locked->payroll_period_id === $payrollPeriodId) {
                    return $locked; // replay
                }
                throw ApiException::make('PAYROLL_ALREADY_INCLUDED', 'This reimbursement is already included in payroll.', 409);
            }
            if ($locked->status === S::Paid || ExpensePayment::query()->where('expense_request_id', $locked->id)->lockForUpdate()->first() !== null) {
                throw ApiException::make('ALREADY_PAID_SEPARATELY', 'This reimbursement was already paid and cannot be added to payroll.', 409);
            }
            if (! in_array($locked->status, [S::Approved, S::ScheduledForPayment], true)) {
                throw ApiException::make('INVALID_TRANSITION', "A reimbursement that is {$locked->status->value} cannot be included in payroll.", 409, ['status' => $locked->status->value]);
            }
            if ($locked->payment_route !== 'PAYROLL') {
                throw ApiException::make('PAYMENT_ROUTE_SEPARATE', 'This reimbursement is paid separately and must not appear in payroll.', 409);
            }

            $from = $locked->status;
            if ($from === S::Approved) {
                ExpenseStateMachine::assert($from, S::ScheduledForPayment);
                $locked->status = S::ScheduledForPayment;
                $locked->scheduled_at = now();
            }
            $locked->fill(['payroll_item_line_id' => $payrollItemLineId, 'payroll_period_id' => $payrollPeriodId, 'payroll_included_at' => now()])->save();

            ExpenseRequestEvent::query()->create([
                'expense_request_id' => $locked->id, 'action' => 'payroll_included', 'from_status' => $from->value, 'to_status' => $locked->status->value,
                'data' => ['payroll_item_line_id' => $payrollItemLineId, 'payroll_period_id' => $payrollPeriodId, 'amount' => (string) $locked->approved_amount],
            ]);
            $this->activity->log('expense.payroll_included', $locked, ['status' => $from->value], [
                'status' => $locked->status->value, 'payroll_item_line_id' => $payrollItemLineId, 'payroll_period_id' => $payrollPeriodId,
            ]);

            return $locked;
        });
    }

    /** Called when payroll for the period is marked paid: claims included in it become PAID (method PAYROLL). @return int claims settled */
    public function settlePeriod(int $payrollPeriodId, ?User $actor = null): int
    {
        $settled = 0;
        $ids = ExpenseRequest::query()->where('payroll_period_id', $payrollPeriodId)->where('status', S::ScheduledForPayment->value)->pluck('id');

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $actor, &$settled) {
                $locked = ExpenseRequest::query()->whereKey($id)->lockForUpdate()->first();
                if (! $locked || $locked->status !== S::ScheduledForPayment || ExpensePayment::query()->where('expense_request_id', $locked->id)->lockForUpdate()->first() !== null) {
                    return;
                }
                ExpenseStateMachine::assert($locked->status, S::Paid);

                $payment = ExpensePayment::query()->create([
                    'expense_request_id' => $locked->id, 'idempotency_key' => 'payroll:'.$locked->id, 'amount' => $locked->approved_amount, 'currency' => $locked->currency,
                    'method' => 'PAYROLL', 'reference' => 'payroll-period:'.$locked->payroll_period_id, 'paid_at' => now(), 'paid_by' => $actor?->id,
                ]);
                $locked->fill(['status' => S::Paid, 'paid_at' => $payment->paid_at, 'paid_by' => $actor?->id])->save();
                ExpenseRequestEvent::query()->create([
                    'expense_request_id' => $locked->id, 'action' => 'paid', 'from_status' => S::ScheduledForPayment->value, 'to_status' => S::Paid->value,
                    'actor_id' => $actor?->id, 'data' => ['payment_id' => $payment->id, 'method' => 'PAYROLL', 'payroll_period_id' => $locked->payroll_period_id],
                ]);
                $this->activity->log('expense.paid', $locked, ['status' => S::ScheduledForPayment->value], ['status' => S::Paid->value, 'method' => 'PAYROLL'], actor: $actor);
                $this->notifications->notify($locked->employee, 'expense.paid', 'Reimbursement paid with your salary', $locked->request_number, $locked,
                    ['request_number' => $locked->request_number], "expense:{$locked->id}:paid");
                $settled++;
            });
        }

        return $settled;
    }
}
