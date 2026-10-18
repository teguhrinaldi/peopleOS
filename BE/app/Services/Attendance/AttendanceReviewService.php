<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;
use App\Exceptions\ApiException;
use App\Models\Attendance;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * HR review of attendance flagged by the "review" geofence mode (status PENDING).
 * Approve restores the natural lifecycle status; reject marks the record REJECTED with a reason.
 */
class AttendanceReviewService
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function approve(User $reviewer, Attendance $attendance, ?string $note = null): Attendance
    {
        return DB::transaction(function () use ($reviewer, $attendance, $note) {
            $locked = $this->lockPending($attendance);
            Gate::forUser($reviewer)->authorize('review', $locked);

            $locked->fill([
                'status' => $locked->check_out_at ? AttendanceStatus::CheckedOut : AttendanceStatus::CheckedIn,
                'needs_review' => false,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();

            $this->activity->log('attendance.review_approved', $locked, ['status' => 'PENDING'], [
                'status' => $locked->status->value, 'review_note' => $note,
            ], actor: $reviewer);

            return $locked->refresh();
        });
    }

    public function reject(User $reviewer, Attendance $attendance, string $note): Attendance
    {
        if (trim($note) === '') {
            throw ApiException::make('REJECTION_REASON_REQUIRED', 'A reason is required to reject attendance.');
        }

        return DB::transaction(function () use ($reviewer, $attendance, $note) {
            $locked = $this->lockPending($attendance);
            Gate::forUser($reviewer)->authorize('review', $locked);

            $locked->fill([
                'status' => AttendanceStatus::Rejected,
                'needs_review' => false,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();

            $this->activity->log('attendance.review_rejected', $locked, ['status' => 'PENDING'], [
                'status' => AttendanceStatus::Rejected->value, 'review_note' => $note,
            ], actor: $reviewer);

            return $locked->refresh();
        });
    }

    private function lockPending(Attendance $attendance): Attendance
    {
        $locked = Attendance::query()->whereKey($attendance->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== AttendanceStatus::Pending) {
            throw ApiException::make('REQUEST_NOT_PENDING', 'This attendance is not awaiting review.', 409);
        }

        return $locked;
    }
}
