<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Answers "which employees may this user see/act on?" from permissions only, never from role names.
 *
 *  - holds the *all* permission           -> every employee in the company
 *  - holds the *team* permission          -> direct reports (by their current assignment)
 *  - $includeSelf                         -> their own employee record
 *  - otherwise                            -> nobody
 *
 * Every controller and policy funnels through here, so an id in a URL or body can never widen access.
 */
class AccessScope
{
    public function __construct(private readonly CompanyContext $context) {}

    public function employees(User $user, ?string $allPermission, ?string $teamPermission = null, bool $includeSelf = true): Builder
    {
        $query = Employee::query()->where('employees.company_id', $this->context->id());

        if (! $user->is_active) {
            return $query->whereRaw('1 = 0');
        }

        if ($allPermission !== null && $user->hasPermission($allPermission)) {
            return $query;
        }

        $me = $user->employee;

        return $query->where(function (Builder $w) use ($user, $me, $teamPermission, $includeSelf) {
            $w->whereRaw('1 = 0');

            if ($me && $teamPermission !== null && $user->hasPermission($teamPermission)) {
                $w->orWhere(fn (Builder $q) => $q->directReportsOf($me->id));
            }

            if ($me && $includeSelf) {
                $w->orWhere('employees.id', $me->id);
            }
        });
    }

    public function canAccess(User $user, Employee|int $employee, ?string $allPermission, ?string $teamPermission = null, bool $includeSelf = true): bool
    {
        $id = $employee instanceof Employee ? $employee->id : $employee;

        return $this->employees($user, $allPermission, $teamPermission, $includeSelf)
            ->where('employees.id', $id)
            ->exists();
    }

    public function isSelf(User $user, Employee|int $employee): bool
    {
        $id = $employee instanceof Employee ? $employee->id : $employee;

        return $user->employee?->id === $id;
    }
}
