<?php

namespace App\Services\Reimbursements;

use App\Models\ExpensePolicy;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\CompanyContext;
use Illuminate\Validation\ValidationException;

/** Company-wide reimbursement policy (one DB row). Defaults apply until an administrator saves it. */
class ExpensePolicyService
{
    public function __construct(private readonly CompanyContext $context, private readonly ActivityLogger $activity) {}

    public function current(): ExpensePolicy
    {
        return ExpensePolicy::query()->firstOrCreate(
            ['company_id' => $this->context->id()],
            ['approval_route' => 'MANAGER_OR_HR', 'payment_route' => 'SEPARATE', 'max_receipt_kb' => 5120],
        )->refresh();
    }

    /** Effective upload ceiling in KB: the policy value, never above the infrastructure ceiling. */
    public function maxReceiptKb(): int
    {
        return max(1, min($this->current()->max_receipt_kb, (int) config('reimbursements.max_kb_ceiling', 10240)));
    }

    /** @param array<string, mixed> $data keys present = fields to change */
    public function update(User $actor, array $data): ExpensePolicy
    {
        $policy = $this->current();
        $allowed = array_intersect_key($data, array_flip(['approval_route', 'payment_route', 'max_amount_per_claim', 'max_amount_per_month', 'max_receipt_kb']));

        if (isset($allowed['approval_route']) && ! in_array($allowed['approval_route'], ExpensePolicy::APPROVAL_ROUTES, true)) {
            throw ValidationException::withMessages(['approval_route' => 'Invalid approval route.']);
        }
        if (isset($allowed['payment_route']) && ! in_array($allowed['payment_route'], ExpensePolicy::PAYMENT_ROUTES, true)) {
            throw ValidationException::withMessages(['payment_route' => 'Invalid payment route.']);
        }

        $policy->fill($allowed);
        if (! $policy->isDirty()) {
            return $policy;
        }
        $policy->updated_by = $actor->id;
        [$old, $new] = $this->activity->diff($policy);
        $policy->save();
        $this->activity->log('expense_policy.updated', $policy, $old, $new, actor: $actor);

        return $policy->refresh();
    }
}
