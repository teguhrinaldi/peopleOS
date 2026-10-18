<?php

namespace App\Services\Admin;

use App\Enums\RequestStatus;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Services\AccessScope;
use App\Services\Attendance\AttendanceReviewService;
use App\Services\Attendance\CorrectionService;
use App\Services\Attendance\OvertimeService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Review queues for the web admin. Listing is scoped by AccessScope and never includes the viewer's
 * own requests; every decision is delegated to the domain service, which re-checks the policy,
 * locks the row, enforces "still pending" and writes the audit trail.
 */
class ApprovalQueue
{
    public function __construct(
        private readonly AccessScope $scope,
        private readonly CorrectionService $corrections,
        private readonly OvertimeService $overtime,
        private readonly AttendanceReviewService $reviews,
    ) {}

    // ---- Attendance corrections -----------------------------------------------------------------

    public function corrections(User $user, string $tab): LengthAwarePaginator
    {
        return AttendanceCorrection::query()
            ->whereIn('employee_id', $this->notMine($user, $this->scope->employees($user, 'attendance.correct', 'attendance.approve_team', false)))
            ->when($tab === 'pending', fn (Builder $q) => $q->where('status', RequestStatus::Pending->value), fn (Builder $q) => $q->where('status', '!=', RequestStatus::Pending->value))
            ->with(['employee:id,full_name,employee_number,photo_path', 'reviewer:id,name', 'attendance:id,check_in_at,check_out_at,status'])
            ->orderBy($tab === 'pending' ? 'created_at' : 'reviewed_at', $tab === 'pending' ? 'asc' : 'desc')
            ->orderBy('id')
            ->paginate(25)->withQueryString();
    }

    public function decideCorrection(User $reviewer, AttendanceCorrection $correction, bool $approve, ?string $note): AttendanceCorrection
    {
        return $approve
            ? $this->corrections->approve($reviewer, $correction, $note)
            : $this->corrections->reject($reviewer, $correction, (string) $note);
    }

    // ---- Overtime -------------------------------------------------------------------------------

    public function overtime(User $user, string $tab): LengthAwarePaginator
    {
        $all = $user->hasPermission('overtime.view') ? 'overtime.view' : 'overtime.approve';

        return OvertimeRequest::query()
            ->whereIn('employee_id', $this->notMine($user, $this->scope->employees($user, $all, 'overtime.approve_team', false)))
            ->when($tab === 'pending', fn (Builder $q) => $q->where('status', RequestStatus::Pending->value), fn (Builder $q) => $q->where('status', '!=', RequestStatus::Pending->value))
            ->with(['employee:id,full_name,employee_number,photo_path', 'reviewer:id,name'])
            ->orderBy($tab === 'pending' ? 'start_at' : 'updated_at', $tab === 'pending' ? 'asc' : 'desc')
            ->orderBy('id')
            ->paginate(25)->withQueryString();
    }

    /**
     * Which of these employees may the user decide overtime for? (Seeing is wider than deciding
     * for someone holding overtime.view but only overtime.approve_team.)
     *
     * @param  list<int>  $employeeIds
     * @return list<int>
     */
    public function overtimeDecidable(User $user, array $employeeIds): array
    {
        if ($employeeIds === [] || ! $user->hasAnyPermission('overtime.approve', 'overtime.approve_team')) {
            return [];
        }

        return $this->notMine($user, $this->scope->employees($user, 'overtime.approve', 'overtime.approve_team', false))
            ->whereIn('employees.id', $employeeIds)->pluck('employees.id')->map(fn ($id) => (int) $id)->all();
    }

    public function decideOvertime(User $reviewer, OvertimeRequest $request, bool $approve, ?int $minutes, ?string $note): OvertimeRequest
    {
        return $approve
            ? $this->overtime->approve($reviewer, $request, $minutes, $note)
            : $this->overtime->reject($reviewer, $request, (string) $note);
    }

    // ---- Flagged attendance -------------------------------------------------------------------

    public function review(User $reviewer, Attendance $attendance, bool $approve, ?string $note): Attendance
    {
        // The domain policy lets a reviewer decide their own flagged record; the web admin never offers that.
        abort_if($reviewer->employee?->id === $attendance->employee_id, 403);

        return $approve
            ? $this->reviews->approve($reviewer, $attendance, $note)
            : $this->reviews->reject($reviewer, $attendance, (string) $note);
    }

    private function notMine(User $user, Builder $scope): Builder
    {
        return $scope->when($user->employee, fn (Builder $q, $me) => $q->whereKeyNot($me->id))->select('employees.id');
    }
}
