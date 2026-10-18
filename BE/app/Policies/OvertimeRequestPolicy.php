<?php

namespace App\Policies;

use App\Models\OvertimeRequest;
use App\Models\User;
use App\Services\AccessScope;

class OvertimeRequestPolicy
{
    public function __construct(private readonly AccessScope $scope) {}

    public function view(User $user, OvertimeRequest $request): bool
    {
        if ($this->scope->isSelf($user, $request->employee_id)) {
            return true;
        }

        return $this->reviewable($user, $request)
            || $this->scope->canAccess($user, $request->employee_id, 'overtime.view', 'overtime.approve_team', false);
    }

    public function approve(User $user, OvertimeRequest $request): bool
    {
        return ! $this->scope->isSelf($user, $request->employee_id)
            && $this->reviewable($user, $request);
    }

    public function cancel(User $user, OvertimeRequest $request): bool
    {
        return $this->scope->isSelf($user, $request->employee_id);
    }

    public function reviewable(User $user, OvertimeRequest $request): bool
    {
        return $this->scope->canAccess($user, $request->employee_id, 'overtime.approve', 'overtime.approve_team', false);
    }
}
