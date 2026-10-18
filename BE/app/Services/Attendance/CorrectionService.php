<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\EmployeeStatus;
use App\Enums\RequestStatus;
use App\Exceptions\ApiException;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceSchedule;
use App\Models\Employee;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Notifications\NotificationService;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Attendance correction workflow (e.g. forgot to check out):
 *   employee requests -> manager/HR reviews -> approve applies the times, reject restores the record.
 * Nobody can review their own request; the policy is re-checked here so no caller can bypass it.
 */
class CorrectionService
{
    public function __construct(
        private readonly ScheduleService $schedules,
        private readonly AttendanceService $attendance,
        private readonly ShiftCalculator $calculator,
        private readonly CompanyContext $context,
        private readonly ActivityLogger $activity,
        private readonly NotificationService $notifications,
    ) {}

    public function request(
        Employee $employee,
        User $requestedBy,
        string $workDate,
        ?CarbonImmutable $checkIn,
        ?CarbonImmutable $checkOut,
        string $reason,
    ): AttendanceCorrection {
        if ($employee->status !== EmployeeStatus::Active) {
            throw ApiException::make('EMPLOYEE_NOT_ACTIVE', 'Only active employees can request corrections.', 403);
        }

        if ($checkIn === null && $checkOut === null) {
            throw ApiException::make('VALIDATION_ERROR', 'Provide a corrected check-in time, check-out time, or both.');
        }

        $today = $this->context->today();
        $date = CarbonImmutable::parse($workDate, $this->context->timezone())->startOfDay();
        $window = (int) config('peopleos.attendance.correction_window_days', 30);

        if ($date->greaterThan($today)) {
            throw ApiException::make('INVALID_WORK_DATE', 'Corrections cannot be requested for a future date.');
        }
        if ($date->lessThan($today->subDays($window))) {
            throw ApiException::make('CORRECTION_WINDOW_EXPIRED', "Corrections can only be requested within {$window} days.");
        }

        return DB::transaction(function () use ($employee, $requestedBy, $date, $checkIn, $checkOut, $reason) {
            $schedule = $this->schedules->forDate($employee, $date->toDateString());
            AttendanceSchedule::query()->whereKey($schedule->id)->lockForUpdate()->first();

            if ($schedule->shift_id === null) {
                throw ApiException::make('NO_SCHEDULE', 'There was no shift assigned for that day.');
            }

            $duplicate = AttendanceCorrection::query()
                ->where('employee_id', $employee->id)
                ->where('attendance_schedule_id', $schedule->id)
                ->where('status', RequestStatus::Pending->value)
                ->exists();
            if ($duplicate) {
                throw ApiException::make('CORRECTION_ALREADY_PENDING', 'A correction for that day is already pending.', 409);
            }

            $attendance = Attendance::query()
                ->where('employee_id', $employee->id)
                ->where('attendance_schedule_id', $schedule->id)
                ->lockForUpdate()
                ->first();

            $this->assertPlausible($schedule, $attendance, $checkIn, $checkOut);

            $correction = AttendanceCorrection::query()->create([
                'employee_id' => $employee->id,
                'attendance_id' => $attendance?->id,
                'attendance_schedule_id' => $schedule->id,
                'work_date' => $date->toDateString(),
                'requested_check_in_at' => $checkIn,
                'requested_check_out_at' => $checkOut,
                'reason' => $reason,
                'status' => RequestStatus::Pending,
                'requested_by' => $requestedBy->id,
                'original_snapshot' => $attendance ? [
                    'status' => $attendance->status->value,
                    'check_in_at' => $attendance->check_in_at?->toIso8601String(),
                    'check_out_at' => $attendance->check_out_at?->toIso8601String(),
                ] : null,
            ]);

            if ($attendance) {
                $attendance->status_before_correction = $attendance->status;
                $attendance->status = AttendanceStatus::CorrectionRequested;
                $attendance->save();
            }

            $this->activity->log('attendance_correction.requested', $correction, null, [
                'work_date' => $date->toDateString(),
                'requested_check_in_at' => $checkIn?->toIso8601String(),
                'requested_check_out_at' => $checkOut?->toIso8601String(),
            ], actor: $requestedBy);

            return $correction;
        });
    }

    public function approve(User $reviewer, AttendanceCorrection $correction, ?string $note = null): AttendanceCorrection
    {
        return DB::transaction(function () use ($reviewer, $correction, $note) {
            $locked = $this->lockPending($correction);
            Gate::forUser($reviewer)->authorize('approve', $locked);

            $schedule = $locked->schedule;
            $attendance = Attendance::query()
                ->where('employee_id', $locked->employee_id)
                ->where('attendance_schedule_id', $schedule->id)
                ->lockForUpdate()
                ->first();

            $old = $attendance ? [
                'status' => $attendance->status->value,
                'check_in_at' => $attendance->check_in_at?->toIso8601String(),
                'check_out_at' => $attendance->check_out_at?->toIso8601String(),
            ] : null;

            $checkIn = $locked->requested_check_in_at ?? $attendance?->check_in_at;
            $checkOut = $locked->requested_check_out_at ?? $attendance?->check_out_at;
            $this->assertPlausible($schedule, $attendance, $locked->requested_check_in_at ? CarbonImmutable::instance($locked->requested_check_in_at) : null, $locked->requested_check_out_at ? CarbonImmutable::instance($locked->requested_check_out_at) : null);

            $attendance ??= new Attendance([
                'employee_id' => $locked->employee_id,
                'attendance_schedule_id' => $schedule->id,
                'work_date' => $schedule->work_date->toDateString(),
            ]);
            $attendance->check_in_at = $checkIn;
            $attendance->check_out_at = $checkOut;
            $attendance->status = AttendanceStatus::Corrected;
            $attendance->status_before_correction = null;
            $attendance->needs_review = false;
            $this->attendance->recalculate($attendance, $schedule);
            $attendance->save();

            $locked->fill([
                'status' => RequestStatus::Approved,
                'attendance_id' => $attendance->id,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();

            $this->activity->log('attendance_correction.approved', $locked, $old, [
                'status' => AttendanceStatus::Corrected->value,
                'check_in_at' => $checkIn?->toIso8601String(),
                'check_out_at' => $checkOut?->toIso8601String(),
                'review_note' => $note,
            ], actor: $reviewer);

            $this->notifications->notify($locked->employee, 'attendance_correction.approved', 'Attendance correction approved',
                'Your correction for '.$locked->work_date->format('D, d M').' was approved.', $locked, ['work_date' => $locked->work_date->toDateString()]);

            return $locked->refresh();
        });
    }

    public function reject(User $reviewer, AttendanceCorrection $correction, string $note): AttendanceCorrection
    {
        if (trim($note) === '') {
            throw ApiException::make('REJECTION_REASON_REQUIRED', 'A reason is required to reject a correction.');
        }

        return DB::transaction(function () use ($reviewer, $correction, $note) {
            $locked = $this->lockPending($correction);
            Gate::forUser($reviewer)->authorize('approve', $locked);

            $this->restoreAttendance($locked);
            $locked->fill([
                'status' => RequestStatus::Rejected,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();

            $this->activity->log('attendance_correction.rejected', $locked, null, ['review_note' => $note], actor: $reviewer);
            $this->notifications->notify($locked->employee, 'attendance_correction.rejected', 'Attendance correction rejected',
                $note, $locked, ['work_date' => $locked->work_date->toDateString()]);

            return $locked->refresh();
        });
    }

    public function cancel(User $user, AttendanceCorrection $correction): AttendanceCorrection
    {
        return DB::transaction(function () use ($user, $correction) {
            $locked = $this->lockPending($correction);
            Gate::forUser($user)->authorize('cancel', $locked);

            $this->restoreAttendance($locked);
            $locked->fill(['status' => RequestStatus::Cancelled])->save();

            $this->activity->log('attendance_correction.cancelled', $locked, null, null, actor: $user);

            return $locked->refresh();
        });
    }

    private function lockPending(AttendanceCorrection $correction): AttendanceCorrection
    {
        $locked = AttendanceCorrection::query()->whereKey($correction->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== RequestStatus::Pending) {
            throw ApiException::make('REQUEST_NOT_PENDING', 'This request has already been decided.', 409);
        }

        return $locked;
    }

    private function restoreAttendance(AttendanceCorrection $correction): void
    {
        $attendance = Attendance::query()
            ->where('employee_id', $correction->employee_id)
            ->where('attendance_schedule_id', $correction->attendance_schedule_id)
            ->lockForUpdate()
            ->first();

        if ($attendance && $attendance->status === AttendanceStatus::CorrectionRequested) {
            $attendance->status = $attendance->status_before_correction ?? AttendanceStatus::CheckedOut;
            $attendance->status_before_correction = null;
            $attendance->save();
        }
    }

    /** Resulting check-in/out must exist, be ordered, not be in the future and sit near the shift. */
    private function assertPlausible(AttendanceSchedule $schedule, ?Attendance $attendance, ?CarbonImmutable $checkIn, ?CarbonImmutable $checkOut): void
    {
        $finalIn = $checkIn ?? $attendance?->check_in_at;
        $finalOut = $checkOut ?? $attendance?->check_out_at;
        $now = CarbonImmutable::instance(now());

        if ($finalIn === null) {
            throw ApiException::make('CHECK_IN_REQUIRED', 'A check-in time is required because none was recorded.');
        }
        if ($finalOut !== null && $finalOut->lessThanOrEqualTo($finalIn)) {
            throw ApiException::make('INVALID_EVENT_TIME', 'Check-out must be after check-in.');
        }
        foreach (array_filter([$checkIn, $checkOut]) as $moment) {
            if ($moment->greaterThan($now->addMinutes(5))) {
                throw ApiException::make('INVALID_EVENT_TIME', 'Corrected times cannot be in the future.');
            }
            if ($schedule->expected_start_at && $schedule->expected_end_at) {
                $earliest = CarbonImmutable::instance($schedule->expected_start_at)->subHours(12);
                $latest = CarbonImmutable::instance($schedule->expected_end_at)->addHours(12);
                if ($moment->lessThan($earliest) || $moment->greaterThan($latest)) {
                    throw ApiException::make('INVALID_EVENT_TIME', 'Corrected times must be close to the scheduled shift.');
                }
            }
        }
    }
}
