<?php

namespace App\Policies;

use App\Models\Attendance;
use App\Models\User;
use App\Services\AccessScope;

class AttendanceRecordPolicy
{
    public function __construct(private readonly AccessScope $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission('attendance.self', 'attendance.view', 'attendance.view_team');
    }

    /** Own record, or any/team record with the matching permission. */
    public function view(User $user, Attendance $attendance): bool
    {
        if ($this->scope->isSelf($user, $attendance->employee_id) && $user->hasPermission('attendance.self')) {
            return true;
        }

        return $this->scope->canAccess($user, $attendance->employee_id, 'attendance.view', 'attendance.view_team', false);
    }

    /** Selfies are evidence: the owner and people allowed to see the record may load them. */
    public function viewSelfie(User $user, Attendance $attendance): bool
    {
        return $this->view($user, $attendance);
    }

    public function review(User $user, Attendance $attendance): bool
    {
        // Nobody reviews their own flagged attendance, even with the permission.
        return $user->hasPermission('attendance.review')
            && ! $this->scope->isSelf($user, $attendance->employee_id)
            && $this->scope->canAccess($user, $attendance->employee_id, 'attendance.view', null, false);
    }
}
