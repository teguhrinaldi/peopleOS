<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;
use App\Services\AccessScope;

class EmployeePolicy
{
    public function __construct(private readonly AccessScope $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission('employee.view', 'employee.view_team');
    }

    public function view(User $user, Employee $employee): bool
    {
        return $this->scope->canAccess($user, $employee, 'employee.view', 'employee.view_team');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('employee.create');
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->hasPermission('employee.edit')
            && $this->scope->canAccess($user, $employee, 'employee.view');
    }

    public function delete(User $user, Employee $employee): bool
    {
        return $user->hasPermission('employee.delete')
            && $this->scope->canAccess($user, $employee, 'employee.view')
            && ! $this->scope->isSelf($user, $employee);
    }
}
