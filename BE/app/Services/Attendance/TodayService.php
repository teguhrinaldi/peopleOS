<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\EmployeeStatus;
use App\Enums\RequestStatus;
use App\Http\Resources\AttendanceResource;
use App\Http\Resources\RequestResources;
use App\Http\Resources\ScheduleResource;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceSchedule;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\OvertimeRequest;
use App\Services\Leave\LeaveLedger;
use App\Support\CompanyContext;

/**
 * Read model behind the Home screen: what is today, what is expected, what can the employee do right now.
 * Derived entirely on the server (never hard-coded on the client). Does not write anything.
 */
class TodayService
{
    public function __construct(
        private readonly ScheduleService $schedules,
        private readonly ShiftCalculator $calculator,
        private readonly CompanyContext $context,
        private readonly LeaveLedger $leave,
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(Employee $employee): array
    {
        $now = $this->context->now();

        $open = Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereNotNull('check_in_at')->whereNull('check_out_at')
            ->whereIn('status', [AttendanceStatus::CheckedIn->value, AttendanceStatus::Pending->value])
            ->orderByDesc('check_in_at')
            ->with(['schedule', 'events'])
            ->first();

        // A check-in that was never closed and is older than a shift can last can no longer be checked out
        // (CHECK_OUT_TOO_LATE). It must not trap the employee in a phantom running shift: report it separately
        // so the app can offer a correction, and let today be a normal day.
        $stale = null;
        if ($open && $this->calculator->minutesBetween($open->check_in_at, $now) > AttendanceService::MAX_OPEN_MINUTES) {
            $stale = $open;
            $open = null;
        }

        if ($open) {
            $schedule = $open->schedule;
            $attendance = $open;
        } else {
            $workDate = $this->schedules->workDateFor($employee, $now);
            $schedule = $this->schedules->peek($employee, $workDate);
            $attendance = $schedule->exists
                ? Attendance::query()->where('employee_id', $employee->id)->where('attendance_schedule_id', $schedule->id)->with('events')->first()
                : null;
        }

        [$state, $blocked] = $this->state($employee, $schedule, $attendance);

        $holiday = $schedule->holiday_id ? Holiday::query()->find($schedule->holiday_id) : null;
        $pending = $schedule->exists
            ? AttendanceCorrection::query()
                ->where('employee_id', $employee->id)->where('attendance_schedule_id', $schedule->id)
                ->where('status', RequestStatus::Pending->value)->first()
            : null;

        return [
            'server_time' => $now->toIso8601String(),
            'timezone' => $this->context->timezone(),
            'today' => $now->toDateString(),
            'work_date' => $schedule->work_date->toDateString(),
            'state' => $state,
            'blocked_reason' => $blocked,
            'can_check_in' => $state === 'NOT_CHECKED_IN',
            'can_check_out' => in_array($state, ['CHECKED_IN', 'PENDING_REVIEW_OPEN'], true),
            'elapsed_minutes' => $attendance?->check_in_at && ! $attendance->check_out_at
                ? max(0, $this->calculator->minutesBetween($attendance->check_in_at, $now)) : null,
            'schedule' => (new ScheduleResource($schedule))->resolve(),
            'attendance' => $attendance ? (new AttendanceResource($attendance->setRelation('schedule', $schedule)))->resolve() : null,
            'holiday' => $holiday ? ['name' => $holiday->name, 'date' => $holiday->date->toDateString()] : null,
            'pending_correction' => $pending ? RequestResources::correction($pending) : null,
            'stale_attendance' => $stale ? (new AttendanceResource($stale))->resolve() : null,
        ];
    }

    /** @return array{0: string, 1: ?string} */
    private function state(Employee $employee, AttendanceSchedule $schedule, ?Attendance $attendance): array
    {
        if ($employee->status === EmployeeStatus::Terminated) {
            return ['BLOCKED', 'EMPLOYEE_TERMINATED'];
        }
        if ($employee->status !== EmployeeStatus::Active) {
            return ['BLOCKED', 'EMPLOYEE_NOT_ACTIVE'];
        }

        if ($attendance) {
            $open = $attendance->check_in_at && ! $attendance->check_out_at;

            return match (true) {
                $attendance->status === AttendanceStatus::CorrectionRequested => ['CORRECTION_PENDING', null],
                $attendance->status === AttendanceStatus::Pending => [$open ? 'PENDING_REVIEW_OPEN' : 'PENDING_REVIEW', null],
                $attendance->status === AttendanceStatus::Rejected => ['REJECTED', null],
                $open => ['CHECKED_IN', null],
                default => ['CHECKED_OUT', null],
            };
        }

        if ($schedule->shift_id === null) {
            return ['NO_SCHEDULE', 'NO_SCHEDULE'];
        }

        if ($this->leave->coverageOn($employee, $schedule->work_date->toDateString()) === 'FULL') {
            return ['ON_LEAVE', 'ON_LEAVE'];
        }

        if (! $schedule->is_working_day) {
            $overtime = OvertimeRequest::query()
                ->where('employee_id', $employee->id)
                ->where('status', RequestStatus::Approved->value)
                ->whereDate('work_date', $schedule->work_date->toDateString())
                ->exists();

            return $overtime ? ['NOT_CHECKED_IN', null] : ['NOT_WORKING_DAY', 'NOT_A_WORKING_DAY'];
        }

        return ['NOT_CHECKED_IN', null];
    }
}
