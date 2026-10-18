<?php

namespace App\Policies;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\AccessScope;

class LeaveRequestPolicy
{
    public function __construct(private readonly AccessScope $scope) {}

    public function view(User $user, LeaveRequest $request): bool
    {
        return $this->scope->isSelf($user, $request->employee_id)
            || $this->scope->canAccess($user, $request->employee_id, 'leave.view', 'leave.view_team', false)
            || $this->reviewable($user, $request);
    }

    /** Drafts are edited and submitted by their owner, or by HR on behalf of someone (e.g. no app login). */
    public function update(User $user, LeaveRequest $request): bool
    {
        if ($this->scope->isSelf($user, $request->employee_id)) {
            return $user->hasPermission('leave.request');
        }

        return $this->scope->canAccess($user, $request->employee_id, 'leave.manage', null, false);
    }

    /** Decide = approve or reject. Never one's own request; only inside the reviewer's scope. */
    public function approve(User $user, LeaveRequest $request): bool
    {
        return ! $this->scope->isSelf($user, $request->employee_id) && $this->reviewable($user, $request);
    }

    public function cancel(User $user, LeaveRequest $request): bool
    {
        return $this->scope->isSelf($user, $request->employee_id) || $this->approve($user, $request);
    }

    private function reviewable(User $user, LeaveRequest $request): bool
    {
        return $this->scope->canAccess($user, $request->employee_id, 'leave.approve', 'leave.approve_team', false);
    }
}
