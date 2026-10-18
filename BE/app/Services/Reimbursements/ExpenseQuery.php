<?php

namespace App\Services\Reimbursements;

use App\Models\ExpenseRequest;
use App\Models\User;
use App\Policies\ExpenseRequestPolicy;
use App\Services\AccessScope;
use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\Builder;

/** Visible-set queries. Lists and single reads share this logic with ExpenseRequestPolicy, so they cannot disagree. */
class ExpenseQuery
{
    public function __construct(private readonly AccessScope $scope, private readonly CompanyContext $context) {}

    /** Everything the user may see: own claims (drafts included) + others' non-draft claims inside their view/approve/pay scope. */
    public function visible(User $user): Builder
    {
        $query = ExpenseRequest::query()->where('expense_requests.company_id', $this->context->id());
        $me = $user->employee?->id;

        return $query->where(function (Builder $w) use ($user, $me) {
            $w->whereRaw('1 = 0');
            if ($me) {
                $w->orWhere('expense_requests.employee_id', $me);
            }
            $w->orWhere(function (Builder $others) use ($user) {
                $others->where('expense_requests.status', '!=', 'DRAFT')->where(function (Builder $scoped) use ($user) {
                    $scoped->whereRaw('1 = 0');
                    foreach (ExpenseRequestPolicy::VIEW_PAIRS as [$all, $team]) {
                        if ($user->hasPermission($all) || ($team !== null && $user->hasPermission($team))) {
                            $scoped->orWhereIn('expense_requests.employee_id', $this->scope->employees($user, $all, $team, false)->select('employees.id'));
                        }
                    }
                });
            });
        });
    }

    public function mine(User $user): Builder
    {
        return ExpenseRequest::query()->where('expense_requests.company_id', $this->context->id())
            ->where('expense_requests.employee_id', (int) $user->employee?->id);
    }

    /** What the user may decide: non-draft claims of people in their approve scope, never their own. */
    public function reviewable(User $user): Builder
    {
        $query = ExpenseRequest::query()->where('expense_requests.company_id', $this->context->id())->where('expense_requests.status', '!=', 'DRAFT');
        if ($me = $user->employee?->id) {
            $query->where('expense_requests.employee_id', '!=', $me);
        }

        return $query->whereIn('expense_requests.employee_id', $this->scope->employees($user, 'expense.approve', 'expense.approve_team', false)->select('employees.id'));
    }

    /** What payers work on: non-draft claims of anyone but themselves. */
    public function payable(User $user): Builder
    {
        $query = ExpenseRequest::query()->where('expense_requests.company_id', $this->context->id())->whereIn('expense_requests.status', ['APPROVED', 'SCHEDULED_FOR_PAYMENT', 'PAID']);
        if ($me = $user->employee?->id) {
            $query->where('expense_requests.employee_id', '!=', $me);
        }

        return $query;
    }

    public function findVisible(User $user, int $id): ?ExpenseRequest
    {
        return $this->visible($user)->whereKey($id)->first();
    }
}
