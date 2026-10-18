<?php

namespace App\Services\Reimbursements;

use App\Enums\EmployeeStatus;
use App\Enums\ExpenseStatus as S;
use App\Exceptions\ApiException;
use App\Models\Employee;
use App\Models\ExpenseCategory;
use App\Models\ExpensePayment;
use App\Models\ExpensePolicy;
use App\Models\ExpenseRequest;
use App\Models\ExpenseRequestEvent;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Documents\InspectedFile;
use App\Services\Notifications\NotificationService;
use App\Support\CompanyContext;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Reimbursement lifecycle. Every mutation: transaction, row lock, policy check on the LOCKED row, the single state
 * machine (ExpenseStateMachine), an audit row (ActivityLogger) and an append-only history event. Notifications are
 * delivered after commit and de-duplicated, so a retried action never notifies twice.
 *
 * Lock order: employee (submit only) -> request. Money is handled as decimal strings (bcmath), never floats.
 */
class ReimbursementService
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly Settings $settings,
        private readonly ActivityLogger $activity,
        private readonly NotificationService $notifications,
        private readonly ExpensePolicyService $policies,
        private readonly ExpenseNumberGenerator $numbers,
        private readonly ReceiptFileInspector $inspector,
        private readonly ReceiptStorage $storage,
    ) {}

    /**
     * @param  array{expense_category_id: int, expense_date: string, submitted_amount: string|int|float, business_purpose: string, description?: ?string, currency?: ?string}  $data
     */
    public function create(User $by, Employee $employee, array $data, ?UploadedFile $receipt = null, bool $submit = false): ExpenseRequest
    {
        Gate::forUser($by)->authorize('create', [ExpenseRequest::class, $employee]);
        $this->assertCanRequest($employee);

        $category = $this->activeCategory((int) ($data['expense_category_id'] ?? 0));
        $fields = $this->normalize($data);
        $inspected = $receipt ? $this->inspector->inspect($receipt) : null;

        $this->numbers->ensure();
        $stored = null;

        try {
            $request = DB::transaction(function () use ($by, $employee, $category, $fields, $inspected, &$stored) {
                $request = new ExpenseRequest([
                    'company_id' => $this->context->id(),
                    'request_number' => $this->numbers->next(),
                    'employee_id' => $employee->id,
                    'expense_category_id' => $category->id,
                    'status' => S::Draft,
                    'requested_by' => $by->id,
                ] + $fields);

                if ($inspected) {
                    $stored = $this->storage->store($inspected, $employee->id);
                    $this->fillReceipt($request, $stored, $inspected);
                }

                $request->save();
                $this->event($request, 'created', null, S::Draft, $by, null, ['has_receipt' => $inspected !== null]);
                $this->activity->log('expense.created', $request, null, $this->auditView($request), actor: $by);

                return $request;
            });
        } catch (Throwable $e) {
            if ($stored !== null) {
                $this->storage->discard($stored['disk'], $stored['path']);
            }
            throw $e;
        }

        return $submit ? $this->submit($by, $request) : $request->refresh();
    }

    /** @param array<string, mixed> $data keys present = fields to change */
    public function updateDraft(User $by, ExpenseRequest $request, array $data, ?UploadedFile $receipt = null): ExpenseRequest
    {
        $inspected = $receipt ? $this->inspector->inspect($receipt) : null;
        $stored = null;

        try {
            return DB::transaction(function () use ($by, $request, $data, $inspected, &$stored) {
                $locked = $this->lock($request);
                Gate::forUser($by)->authorize('update', $locked);
                $this->assertStatus($locked, [S::Draft], 'Only a draft can be edited.');

                if (isset($data['expense_category_id']) && (int) $data['expense_category_id'] !== (int) $locked->expense_category_id) {
                    $locked->expense_category_id = $this->activeCategory((int) $data['expense_category_id'])->id;
                }
                $merged = $this->normalize($data + [
                    'expense_date' => $locked->expense_date->toDateString(),
                    'submitted_amount' => (string) $locked->submitted_amount,
                    'business_purpose' => $locked->business_purpose,
                    'description' => $locked->description,
                    'currency' => $locked->currency,
                ]);
                $locked->fill($merged);

                $replacedSha = null;
                if ($inspected) {
                    $replacedSha = $locked->receipt_sha256;
                    $stored = $this->storage->store($inspected, $locked->employee_id);
                    $this->fillReceipt($locked, $stored, $inspected);
                }

                if (! $locked->isDirty()) {
                    return $locked;
                }

                [$old, $new] = $this->activity->diff($locked);
                $locked->save();
                $this->event($locked, 'updated', S::Draft, S::Draft, $by, null, ['fields' => array_keys($new), 'replaced_receipt_sha256' => $replacedSha]);
                $this->activity->log('expense.updated', $locked, $this->withoutPaths($old), $this->withoutPaths($new), actor: $by);

                return $locked;
            });
        } catch (Throwable $e) {
            if ($stored !== null) {
                $this->storage->discard($stored['disk'], $stored['path']);
            }
            throw $e;
        }
    }

    public function attachReceipt(User $by, ExpenseRequest $request, UploadedFile $receipt): ExpenseRequest
    {
        return $this->updateDraft($by, $request, [], $receipt);
    }

    public function submit(User $by, ExpenseRequest $request): ExpenseRequest
    {
        $result = DB::transaction(function () use ($by, $request) {
            $employee = Employee::query()->whereKey($request->employee_id)->lockForUpdate()->firstOrFail();
            $locked = $this->lock($request);
            Gate::forUser($by)->authorize('update', $locked);
            ExpenseStateMachine::assert($locked->status, S::Submitted);
            $this->assertCanRequest($employee);

            $category = ExpenseCategory::query()->findOrFail($locked->expense_category_id);
            if (! $category->is_active) {
                throw ApiException::make('EXPENSE_CATEGORY_UNAVAILABLE', 'This expense category is no longer available.', 422);
            }
            $policy = $this->policies->current();
            $this->assertRules($locked, $category, $policy);

            $locked->fill([
                'status' => S::Submitted,
                'submitted_at' => now(),
                'policy_snapshot' => [
                    'category' => ['code' => $category->code, 'receipt_required' => $category->receipt_required,
                        'max_amount_per_claim' => $category->max_amount_per_claim, 'max_amount_per_month' => $category->max_amount_per_month, 'max_age_days' => $category->max_age_days],
                    'company' => ['max_amount_per_claim' => $policy->max_amount_per_claim, 'max_amount_per_month' => $policy->max_amount_per_month,
                        'approval_route' => $policy->approval_route, 'payment_route' => $policy->payment_route],
                ],
            ])->save();

            $this->event($locked, 'submitted', S::Draft, S::Submitted, $by);
            $this->activity->log('expense.submitted', $locked, ['status' => S::Draft->value], $this->auditView($locked), actor: $by);

            foreach ($this->approversFor($employee, $policy) as $user) {
                $this->notifications->notify($user, 'expense.pending_review', 'Reimbursement to review',
                    "{$employee->full_name} submitted {$locked->request_number} ({$this->money($locked)}).", $locked, ['request_number' => $locked->request_number],
                    "expense:{$locked->id}:submitted:{$user->id}");
            }

            return $locked;
        });

        return $result->refresh();
    }

    /** SUBMITTED -> UNDER_REVIEW. */
    public function startReview(User $reviewer, ExpenseRequest $request): ExpenseRequest
    {
        $result = DB::transaction(function () use ($reviewer, $request) {
            $locked = $this->lock($request);
            Gate::forUser($reviewer)->authorize('approve', $locked);
            ExpenseStateMachine::assert($locked->status, S::UnderReview);

            $locked->fill(['status' => S::UnderReview, 'review_started_by' => $reviewer->id, 'review_started_at' => now()])->save();
            $this->event($locked, 'review_started', S::Submitted, S::UnderReview, $reviewer);
            $this->activity->log('expense.review_started', $locked, ['status' => S::Submitted->value], ['status' => S::UnderReview->value], actor: $reviewer);

            return $locked;
        });

        return $result->refresh();
    }

    /**
     * Approved amount defaults to the submitted amount; it may be LOWER (never higher) and then needs a reason.
     */
    public function approve(User $reviewer, ExpenseRequest $request, string|int|float|null $approvedAmount = null, ?string $adjustmentReason = null, ?string $note = null): ExpenseRequest
    {
        $result = DB::transaction(function () use ($reviewer, $request, $approvedAmount, $adjustmentReason, $note) {
            $locked = $this->lock($request);
            Gate::forUser($reviewer)->authorize('approve', $locked);
            ExpenseStateMachine::assert($locked->status, S::Approved);

            $submitted = $this->decimal($locked->submitted_amount);
            $approved = $approvedAmount === null || $approvedAmount === '' ? $submitted : $this->decimal($approvedAmount, 'approved_amount');
            if (bccomp($approved, '0', 2) <= 0) {
                throw ApiException::make('VALIDATION_ERROR', 'The approved amount must be greater than zero. Reject the claim instead.', 422, ['approved_amount' => ['Must be greater than zero.']]);
            }
            if (bccomp($approved, $submitted, 2) > 0) {
                throw ApiException::make('APPROVED_AMOUNT_EXCEEDS_SUBMITTED', 'The approved amount cannot exceed the submitted amount.', 422, [
                    'submitted_amount' => $submitted, 'approved_amount' => $approved,
                ]);
            }
            $adjusted = bccomp($approved, $submitted, 2) < 0;
            $reason = $this->text($adjustmentReason);
            if ($adjusted && $reason === null) {
                throw ApiException::make('ADJUSTMENT_REASON_REQUIRED', 'Explain why the approved amount is lower than the submitted amount.', 422, ['adjustment_reason' => ['Required when the amount is adjusted.']]);
            }

            $policy = $this->policies->current();
            $locked->fill([
                'status' => S::Approved, 'approved_amount' => $approved, 'adjustment_reason' => $adjusted ? $reason : null,
                'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'review_note' => $this->text($note), 'payment_route' => $policy->payment_route,
            ])->save();

            $data = ['submitted_amount' => $submitted, 'approved_amount' => $approved, 'payment_route' => $policy->payment_route];
            $this->event($locked, 'approved', S::UnderReview, S::Approved, $reviewer, $adjusted ? $reason : $this->text($note), $data);
            $this->activity->log('expense.approved', $locked, ['status' => S::UnderReview->value, 'approved_amount' => null],
                ['status' => S::Approved->value, 'approved_amount' => $approved, 'adjustment_reason' => $adjusted ? $reason : null, 'review_note' => $this->text($note)], actor: $reviewer);

            $this->notifications->notify($locked->employee, 'expense.approved', 'Reimbursement approved',
                "{$locked->request_number}: {$this->money($locked, approved: true)} approved".($adjusted ? ' (adjusted: '.$reason.')' : '').'.', $locked,
                ['request_number' => $locked->request_number], "expense:{$locked->id}:approved");

            if ($policy->payment_route === 'SEPARATE') {
                foreach ($this->usersWith('expense.pay', exclude: $locked->employee->user_id) as $payer) {
                    $this->notifications->notify($payer, 'expense.ready_for_payment', 'Reimbursement ready for payment',
                        "{$locked->request_number} for {$locked->employee->full_name} was approved.", $locked, ['request_number' => $locked->request_number],
                        "expense:{$locked->id}:payable:{$payer->id}");
                }
            }

            return $locked;
        });

        return $result->refresh();
    }

    public function reject(User $reviewer, ExpenseRequest $request, string $reason): ExpenseRequest
    {
        $reason = $this->text($reason);
        if ($reason === null) {
            throw ApiException::make('REJECTION_REASON_REQUIRED', 'A reason is required to reject a reimbursement.', 422, ['reason' => ['Required.']]);
        }

        $result = DB::transaction(function () use ($reviewer, $request, $reason) {
            $locked = $this->lock($request);
            Gate::forUser($reviewer)->authorize('approve', $locked);
            ExpenseStateMachine::assert($locked->status, S::Rejected);

            $locked->fill(['status' => S::Rejected, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'rejection_reason' => $reason])->save();
            $this->event($locked, 'rejected', S::UnderReview, S::Rejected, $reviewer, $reason);
            $this->activity->log('expense.rejected', $locked, ['status' => S::UnderReview->value], ['status' => S::Rejected->value, 'rejection_reason' => $reason], actor: $reviewer);
            $this->notifications->notify($locked->employee, 'expense.rejected', 'Reimbursement rejected', "{$locked->request_number}: {$reason}", $locked,
                ['request_number' => $locked->request_number], "expense:{$locked->id}:rejected");

            return $locked;
        });

        return $result->refresh();
    }

    /** The owner withdraws their own claim, only before a decision. */
    public function withdraw(User $by, ExpenseRequest $request, ?string $reason = null): ExpenseRequest
    {
        return $this->cancelInternal($by, $request, $this->text($reason), 'withdraw', 'expense.withdrawn');
    }

    /** HR / admin soft-cancel (history is kept). Not possible once paid or included in payroll. */
    public function cancel(User $by, ExpenseRequest $request, string $reason): ExpenseRequest
    {
        $reason = $this->text($reason);
        if ($reason === null) {
            throw ApiException::make('CANCEL_REASON_REQUIRED', 'A reason is required to cancel a reimbursement.', 422, ['reason' => ['Required.']]);
        }

        return $this->cancelInternal($by, $request, $reason, 'cancel', 'expense.cancelled');
    }

    /** APPROVED -> SCHEDULED_FOR_PAYMENT (SEPARATE payment route only; payroll-routed claims are scheduled by PayrollRouting). */
    public function schedule(User $by, ExpenseRequest $request, ?string $scheduledFor = null): ExpenseRequest
    {
        $result = DB::transaction(function () use ($by, $request, $scheduledFor) {
            $locked = $this->lock($request);
            Gate::forUser($by)->authorize('pay', $locked);
            ExpenseStateMachine::assert($locked->status, S::ScheduledForPayment);

            if ($locked->payment_route === 'PAYROLL') {
                throw ApiException::make('PAYMENT_ROUTE_PAYROLL', 'This claim is reimbursed through payroll and cannot be scheduled for a separate payment.', 409);
            }

            $locked->fill(['status' => S::ScheduledForPayment, 'scheduled_by' => $by->id, 'scheduled_at' => now(), 'scheduled_for' => $scheduledFor])->save();
            $this->event($locked, 'scheduled', S::Approved, S::ScheduledForPayment, $by, null, ['scheduled_for' => $scheduledFor]);
            $this->activity->log('expense.scheduled', $locked, ['status' => S::Approved->value], ['status' => S::ScheduledForPayment->value, 'scheduled_for' => $scheduledFor], actor: $by);
            $this->notifications->notify($locked->employee, 'expense.scheduled', 'Reimbursement scheduled for payment',
                "{$locked->request_number} will be paid".($scheduledFor ? " on {$scheduledFor}" : ' soon').'.', $locked, ['request_number' => $locked->request_number],
                "expense:{$locked->id}:scheduled");

            return $locked;
        });

        return $result->refresh();
    }

    // ------------------------------------------------------------------ internals

    private function cancelInternal(User $by, ExpenseRequest $request, ?string $reason, string $ability, string $action): ExpenseRequest
    {
        $result = DB::transaction(function () use ($by, $request, $reason, $ability, $action) {
            $locked = $this->lock($request);
            Gate::forUser($by)->authorize($ability, $locked);
            ExpenseStateMachine::assert($locked->status, S::Cancelled);

            if ($ability === 'withdraw' && ! in_array($locked->status, [S::Draft, S::Submitted, S::UnderReview], true)) {
                throw ApiException::make('INVALID_TRANSITION', "A reimbursement that is {$locked->status->value} can no longer be withdrawn. Ask HR to cancel it.", 409, ['status' => $locked->status->value]);
            }
            if ($locked->payroll_item_line_id !== null || ExpensePayment::query()->where('expense_request_id', $locked->id)->lockForUpdate()->first() !== null) {
                throw ApiException::make('EXPENSE_ALREADY_SETTLED', 'This claim is already in payroll or paid and cannot be cancelled.', 409);
            }

            $from = $locked->status;
            $locked->fill(['status' => S::Cancelled, 'cancelled_by' => $by->id, 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();
            $this->event($locked, $ability === 'withdraw' ? 'withdrawn' : 'cancelled', $from, S::Cancelled, $by, $reason);
            $this->activity->log($action, $locked, ['status' => $from->value], ['status' => S::Cancelled->value, 'cancel_reason' => $reason], actor: $by);

            if ($ability === 'cancel' && (int) $locked->employee_id !== (int) $by->employee?->id) {
                $this->notifications->notify($locked->employee, 'expense.cancelled', 'Reimbursement cancelled', "{$locked->request_number}: ".($reason ?? ''), $locked,
                    ['request_number' => $locked->request_number], "expense:{$locked->id}:cancelled");
            }

            return $locked;
        });

        return $result->refresh();
    }

    /** Submission rules: receipt, per-claim and monthly limits (category AND company), expense age. */
    private function assertRules(ExpenseRequest $request, ExpenseCategory $category, ExpensePolicy $policy): void
    {
        $today = $this->context->today();
        $date = CarbonImmutable::parse($request->expense_date->toDateString(), $this->context->timezone())->startOfDay();

        if ($date->greaterThan($today)) {
            throw ApiException::make('EXPENSE_DATE_IN_FUTURE', 'The expense date cannot be in the future.', 422);
        }
        if ($category->max_age_days !== null && $date->lessThan($today->subDays($category->max_age_days))) {
            throw ApiException::make('EXPENSE_TOO_OLD', "{$category->name} claims must be submitted within {$category->max_age_days} day(s) of the expense.", 422, ['max_age_days' => $category->max_age_days]);
        }
        if ($category->receipt_required && ! $request->hasReceipt()) {
            throw ApiException::make('RECEIPT_REQUIRED', "A receipt is required for {$category->name}.", 422, ['receipt' => ['Required.']]);
        }

        $amount = $this->decimal($request->submitted_amount);
        foreach ([['category', $category->max_amount_per_claim], ['company', $policy->max_amount_per_claim]] as [$scope, $limit]) {
            if ($limit !== null && bccomp($amount, (string) $limit, 2) > 0) {
                throw ApiException::make('EXPENSE_LIMIT_EXCEEDED', "The amount exceeds the {$scope} limit of {$limit} per claim.", 422, ['limit' => $this->decimal($limit), 'scope' => $scope]);
            }
        }

        $start = $date->startOfMonth()->toDateString();
        $end = $date->endOfMonth()->toDateString();
        $base = ExpenseRequest::query()->where('employee_id', $request->employee_id)->where('id', '!=', $request->id)
            ->whereBetween('expense_date', [$start, $end])->whereIn('status', array_map(fn (S $s) => $s->value, S::counted()));

        foreach ([['category', $category->max_amount_per_month, (clone $base)->where('expense_category_id', $category->id)],
            ['company', $policy->max_amount_per_month, clone $base]] as [$scope, $limit, $query]) {
            if ($limit === null) {
                continue;
            }
            $used = $this->decimal((string) ($query->sum(DB::raw('COALESCE(approved_amount, submitted_amount)')) ?? 0));
            if (bccomp(bcadd($used, $amount, 2), (string) $limit, 2) > 0) {
                throw ApiException::make('EXPENSE_MONTHLY_LIMIT_EXCEEDED', "This claim would exceed the {$scope} monthly limit of {$limit} (already claimed: {$used}).", 422, [
                    'limit' => $this->decimal($limit), 'already_claimed' => $used, 'scope' => $scope, 'month' => $date->format('Y-m'),
                ]);
            }
        }
    }

    /** @param array<string, mixed> $data @return array<string, mixed> validated, normalized model fields */
    private function normalize(array $data): array
    {
        $currency = strtoupper(trim((string) ($data['currency'] ?? '')));
        $company = strtoupper($this->settings->string('general.currency'));
        if ($currency === '') {
            $currency = $company;
        }
        if ($currency !== $company) {
            throw ApiException::make('CURRENCY_NOT_SUPPORTED', "Only {$company} claims are supported.", 422, ['currency' => ["Use {$company}."]]);
        }

        $amount = $this->decimal($data['submitted_amount'] ?? null, 'submitted_amount');
        if (bccomp($amount, '0', 2) <= 0) {
            throw ApiException::make('VALIDATION_ERROR', 'The amount must be greater than zero.', 422, ['submitted_amount' => ['Must be greater than zero.']]);
        }

        $date = (string) ($data['expense_date'] ?? '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            throw ApiException::make('VALIDATION_ERROR', 'The expense date must be a valid Y-m-d date.', 422, ['expense_date' => ['Invalid date.']]);
        }
        if ($date > $this->context->today()->toDateString()) {
            throw ApiException::make('EXPENSE_DATE_IN_FUTURE', 'The expense date cannot be in the future.', 422);
        }

        $purpose = $this->text($data['business_purpose'] ?? null, 255);
        if ($purpose === null) {
            throw ApiException::make('VALIDATION_ERROR', 'The business purpose is required.', 422, ['business_purpose' => ['Required.']]);
        }

        return [
            'currency' => $currency, 'submitted_amount' => $amount, 'expense_date' => $date,
            'business_purpose' => $purpose, 'description' => $this->text($data['description'] ?? null, 5000),
        ];
    }

    private function decimal(mixed $value, string $field = 'amount'): string
    {
        $text = trim((string) $value);
        if (! preg_match('/^\d{1,13}(\.\d{1,2})?$/', $text)) {
            throw ApiException::make('VALIDATION_ERROR', "The {$field} must be a positive number with at most 2 decimals.", 422, [$field => ['Invalid amount.']]);
        }

        return bcadd($text, '0', 2);
    }

    private function text(?string $value, int $max = 500): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** @param array{disk: string, path: string} $stored */
    private function fillReceipt(ExpenseRequest $request, array $stored, InspectedFile $file): void
    {
        $request->fill([
            'receipt_disk' => $stored['disk'], 'receipt_path' => $stored['path'], 'receipt_original_name' => $file->displayName,
            'receipt_mime' => $file->mimeType, 'receipt_extension' => $file->extension, 'receipt_size_bytes' => $file->sizeBytes, 'receipt_sha256' => $file->sha256,
        ]);
    }

    private function lock(ExpenseRequest $request): ExpenseRequest
    {
        return ExpenseRequest::query()->where('company_id', $this->context->id())->whereKey($request->id)->lockForUpdate()->firstOrFail();
    }

    /** @param list<S> $allowed */
    private function assertStatus(ExpenseRequest $locked, array $allowed, string $message): void
    {
        if (! in_array($locked->status, $allowed, true)) {
            throw ApiException::make('INVALID_TRANSITION', $message, 409, ['status' => $locked->status->value]);
        }
    }

    private function assertCanRequest(Employee $employee): void
    {
        if ($employee->status !== EmployeeStatus::Active) {
            throw ApiException::make('EMPLOYEE_NOT_ACTIVE', 'Only active employees can submit reimbursements.', 403);
        }
    }

    private function activeCategory(int $id): ExpenseCategory
    {
        $category = ExpenseCategory::query()->where('company_id', $this->context->id())->find($id);
        if (! $category || ! $category->is_active) {
            throw ApiException::make('EXPENSE_CATEGORY_UNAVAILABLE', 'This expense category is not available.', 422, ['expense_category_id' => ['Unavailable.']]);
        }

        return $category;
    }

    /** @param array<string, mixed>|null $data */
    private function event(ExpenseRequest $request, string $action, ?S $from, ?S $to, ?User $actor, ?string $note = null, ?array $data = null): void
    {
        ExpenseRequestEvent::query()->create([
            'expense_request_id' => $request->id, 'action' => $action, 'from_status' => $from?->value, 'to_status' => $to?->value,
            'actor_id' => $actor?->id, 'note' => $note, 'data' => $data,
        ]);
    }

    /** @return array<string, mixed> */
    private function auditView(ExpenseRequest $r): array
    {
        return [
            'request_number' => $r->request_number, 'employee_id' => $r->employee_id, 'expense_category_id' => $r->expense_category_id,
            'expense_date' => $r->expense_date->toDateString(), 'submitted_amount' => (string) $r->submitted_amount, 'currency' => $r->currency,
            'status' => $r->status->value, 'receipt_sha256' => $r->receipt_sha256,
        ];
    }

    /** @param array<string, mixed> $values @return array<string, mixed> storage location never reaches the audit trail */
    private function withoutPaths(array $values): array
    {
        unset($values['receipt_disk'], $values['receipt_path']);

        return $values;
    }

    private function money(ExpenseRequest $r, bool $approved = false): string
    {
        return $r->currency.' '.number_format((float) ($approved ? $r->approved_amount : $r->submitted_amount), 2);
    }

    /** @return list<User> */
    private function approversFor(Employee $employee, ExpensePolicy $policy): array
    {
        $managerUser = $employee->currentAssignment?->manager?->user;
        if ($policy->approval_route === 'MANAGER_OR_HR' && $managerUser && $managerUser->hasAnyPermission('expense.approve_team', 'expense.approve')) {
            return [$managerUser];
        }

        return $this->usersWith('expense.approve', exclude: $employee->user_id);
    }

    /** @return list<User> active users holding a permission through any role (capped) */
    private function usersWith(string $slug, ?int $exclude = null): array
    {
        return User::query()->where('is_active', true)
            ->whereHas('roles.permissions', fn ($q) => $q->where('slug', $slug))
            ->when($exclude, fn ($q) => $q->where('users.id', '!=', $exclude))
            ->limit(25)->get()->all();
    }
}
