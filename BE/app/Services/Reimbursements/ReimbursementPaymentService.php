<?php

namespace App\Services\Reimbursements;

use App\Enums\ExpenseStatus as S;
use App\Exceptions\ApiException;
use App\Models\ExpensePayment;
use App\Models\ExpenseRequest;
use App\Models\ExpenseRequestEvent;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Notifications\NotificationService;
use App\Support\CompanyContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Records the (separate) payment of a reimbursement exactly once.
 *
 * Guarantees, in layers: the request row is locked FOR UPDATE; the idempotency key is checked first (same key + same
 * request = replay of the stored result, same key + another request = 409); status must be SCHEDULED_FOR_PAYMENT;
 * expense_payments has UNIQUE(expense_request_id) and UNIQUE(idempotency_key) as the final backstop; payments are append-only.
 */
class ReimbursementPaymentService
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly ActivityLogger $activity,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  array{method: string, reference?: ?string, paid_at?: ?string, note?: ?string}  $data
     * @return array{0: ExpenseRequest, 1: ExpensePayment, 2: bool} request, payment, replayed
     */
    public function pay(User $by, ExpenseRequest $request, string $idempotencyKey, array $data): array
    {
        $key = trim($idempotencyKey);
        if ($key === '' || mb_strlen($key) > 100) {
            throw ApiException::make('VALIDATION_ERROR', 'An idempotency key of 1-100 characters is required.', 422, ['idempotency_key' => ['Required.']]);
        }
        $method = strtoupper((string) ($data['method'] ?? ''));
        if (! in_array($method, ExpensePayment::METHODS, true) || $method === 'PAYROLL') {
            throw ApiException::make('VALIDATION_ERROR', 'Choose a valid payment method.', 422, ['method' => ['Invalid method.']]);
        }

        try {
            return $this->run($by, $request, $key, $method, $data);
        } catch (UniqueConstraintViolationException) {
            // Lost a race that the lock did not serialise (should not happen); resolve from committed state.
            $existing = ExpensePayment::query()->where('idempotency_key', $key)->first();
            if ($existing && (int) $existing->expense_request_id === (int) $request->id) {
                return [$request->refresh(), $existing, true];
            }
            throw ApiException::make('ALREADY_PAID', 'This reimbursement has already been paid.', 409);
        }
    }

    /** @param array<string, mixed> $data @return array{0: ExpenseRequest, 1: ExpensePayment, 2: bool} */
    private function run(User $by, ExpenseRequest $request, string $key, string $method, array $data): array
    {
        return DB::transaction(function () use ($by, $request, $key, $method, $data) {
            $locked = ExpenseRequest::query()->where('company_id', $this->context->id())->whereKey($request->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($by)->authorize('pay', $locked);

            // Locking reads: they see rows committed while we waited for the claim lock (a plain read would use an older snapshot).
            $byKey = ExpensePayment::query()->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($byKey) {
                if ((int) $byKey->expense_request_id !== (int) $locked->id) {
                    throw ApiException::make('IDEMPOTENCY_KEY_REUSED', 'This idempotency key was already used for another reimbursement.', 409);
                }

                return [$locked->refresh(), $byKey, true];
            }

            if ($locked->status === S::Paid || ExpensePayment::query()->where('expense_request_id', $locked->id)->lockForUpdate()->first() !== null) {
                throw ApiException::make('ALREADY_PAID', 'This reimbursement has already been paid.', 409, ['status' => $locked->status->value]);
            }
            ExpenseStateMachine::assert($locked->status, S::Paid);
            if ($locked->payment_route === 'PAYROLL' || $locked->payroll_item_line_id !== null) {
                throw ApiException::make('PAYMENT_ROUTE_PAYROLL', 'This claim is reimbursed through payroll and cannot be paid separately.', 409);
            }

            $paidAt = ! empty($data['paid_at']) ? $this->context->toLocal((string) $data['paid_at'])->utc() : now();
            if ($paidAt->isFuture() && $paidAt->diffInMinutes(now()) > 5) {
                throw ApiException::make('VALIDATION_ERROR', 'The payment date cannot be in the future.', 422, ['paid_at' => ['Cannot be in the future.']]);
            }

            $payment = ExpensePayment::query()->create([
                'expense_request_id' => $locked->id, 'idempotency_key' => $key, 'amount' => $locked->approved_amount, 'currency' => $locked->currency,
                'method' => $method, 'reference' => $this->clip($data['reference'] ?? null, 120), 'paid_at' => $paidAt, 'paid_by' => $by->id,
                'note' => $this->clip($data['note'] ?? null, 500),
            ]);

            $locked->fill(['status' => S::Paid, 'paid_at' => $paidAt, 'paid_by' => $by->id])->save();

            ExpenseRequestEvent::query()->create([
                'expense_request_id' => $locked->id, 'action' => 'paid', 'from_status' => S::ScheduledForPayment->value, 'to_status' => S::Paid->value,
                'actor_id' => $by->id, 'note' => $payment->note, 'data' => ['payment_id' => $payment->id, 'amount' => (string) $payment->amount, 'method' => $method, 'reference' => $payment->reference],
            ]);
            $this->activity->log('expense.paid', $locked, ['status' => S::ScheduledForPayment->value],
                ['status' => S::Paid->value, 'amount' => (string) $payment->amount, 'method' => $method, 'reference' => $payment->reference, 'idempotency_key' => $key], actor: $by);
            $this->notifications->notify($locked->employee, 'expense.paid', 'Reimbursement paid',
                "{$locked->request_number}: {$locked->currency} ".number_format((float) $payment->amount, 2).' was paid.', $locked,
                ['request_number' => $locked->request_number], "expense:{$locked->id}:paid");

            return [$locked->refresh(), $payment, false];
        });
    }

    private function clip(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
