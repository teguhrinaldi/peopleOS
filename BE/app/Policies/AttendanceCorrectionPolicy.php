<?php

namespace App\Policies;

use App\Models\AttendanceCorrection;
use App\Models\User;
use App\Services\AccessScope;

class AttendanceCorrectionPolicy
{
    public function __construct(private readonly AccessScope $scope) {}

    public function view(User $user, AttendanceCorrection $correction): bool
    {
        if ($this->scope->isSelf($user, $correction->employee_id)) {
            return true;
        }

        return $this->reviewable($user, $correction)
            || $this->scope->canAccess($user, $correction->employee_id, 'attendance.view', 'attendance.view_team', false);
    }

    /**
     * Can this user decide this employee's requests? Never their own, and only inside their approval
     * scope. Whether the request is still pending is the service's rule (409 REQUEST_NOT_PENDING).
     */
    public function approve(User $user, AttendanceCorrection $correction): bool
    {
        return ! $this->scope->isSelf($user, $correction->employee_id)
            && $this->reviewable($user, $correction);
    }

    public function cancel(User $user, AttendanceCorrection $correction): bool
    {
        return $this->scope->isSelf($user, $correction->employee_id);
    }

    public function reviewable(User $user, AttendanceCorrection $correction): bool
    {
        return $this->scope->canAccess($user, $correction->employee_id, 'attendance.correct', 'attendance.approve_team', false);
    }
}
