<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\ExpenseRequest;
use App\Models\User;
use App\Services\AccessScope;
use App\Services\Reimbursements\ExpensePolicyService;
use App\Support\CompanyContext;

/**
 * Permission-based only (never role names), evaluated on the LOADED record so guessed ids cannot widen access.
 *
 *  own claims                       -> always visible to the owner (drafts included)
 *  expense.view / .view_team        -> everyone / direct reports (never other people's drafts)
 *  expense.approve / .approve_team  -> decide for everyone / direct reports; NEVER one's own claim
 *  expense.pay                      -> schedule and pay (never one's own claim)
 *  expense.manage                   -> cancel before payment, configure categories and policy
 */
class ExpenseRequestPolicy
{
    /** [all permission, team permission] pairs that grant visibility. */
    public const VIEW_PAIRS = [['expense.view', 'expense.view_team'], ['expense.approve', 'expense.approve_team'], ['expense.pay', null], ['expense.manage', null]];

    public function __construct(
        private readonly AccessScope $scope,
        private readonly CompanyContext $context,
        private readonly ExpensePolicyService $policies,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission('expense.request', 'expense.view', 'expense.view_team', 'expense.approve', 'expense.approve_team', 'expense.pay', 'expense.manage');
    }

    public function create(User $user, Employee $employee): bool
    {
        return $this->scope->isSelf($user, $employee->id) && $user->hasPermission('expense.request');
    }

    public function view(User $user, ExpenseRequest $request): bool
    {
        if (! $this->sameCompany($request)) {
            return false;
        }
        if ($this->scope->isSelf($user, $request->employee_id)) {
            return true;
        }
        if ($request->status->value === 'DRAFT') {
            return false; // drafts are private to their author
        }
        foreach (self::VIEW_PAIRS as [$all, $team]) {
            if ($this->scope->canAccess($user, $request->employee_id, $all, $team, false)) {
                return true;
            }
        }

        return false;
    }

    public function download(User $user, ExpenseRequest $request): bool
    {
        return $this->view($user, $request);
    }

    /** Edit, attach a receipt, submit: the owner only. */
    public function update(User $user, ExpenseRequest $request): bool
    {
        return $this->sameCompany($request) && $this->scope->isSelf($user, $request->employee_id) && $user->hasPermission('expense.request');
    }

    public function withdraw(User $user, ExpenseRequest $request): bool
    {
        return $this->update($user, $request);
    }

    /** Start review / approve / reject. Never one's own claim, only inside the reviewer's scope. */
    public function approve(User $user, ExpenseRequest $request): bool
    {
        if (! $this->sameCompany($request) || $this->scope->isSelf($user, $request->employee_id) || $request->status->value === 'DRAFT') {
            return false;
        }
        if ($user->hasPermission('expense.approve')) {
            return $this->scope->canAccess($user, $request->employee_id, 'expense.approve', null, false);
        }

        return $this->policies->current()->approval_route === 'MANAGER_OR_HR'
            && $this->scope->canAccess($user, $request->employee_id, null, 'expense.approve_team', false);
    }

    public function pay(User $user, ExpenseRequest $request): bool
    {
        return $this->sameCompany($request) && $user->hasPermission('expense.pay') && ! $this->scope->isSelf($user, $request->employee_id);
    }

    public function cancel(User $user, ExpenseRequest $request): bool
    {
        return $this->sameCompany($request) && $user->hasPermission('expense.manage') && $request->status->value !== 'DRAFT';
    }

    private function sameCompany(ExpenseRequest $request): bool
    {
        return (int) $request->company_id === $this->context->id();
    }
}
