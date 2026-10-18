<?php

namespace App\Services\Attendance;

use App\Enums\DayType;
use App\Enums\GeofenceMode;
use App\Models\Attendance;
use App\Models\AttendancePolicy;
use App\Models\AttendanceSchedule;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\WorkingCalendar;
use App\Models\WorkingCalendarAssignment;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Resolves what was expected of an employee on a work date and freezes it as a snapshot.
 *
 * Precedence for the shift:    shift_assignments (date-ranged override) > employee_assignments.shift
 * Precedence for the calendar: working_calendar_assignments > company default calendar > Mon-Fri
 * Precedence for the policy:   work location policy > company default policy > built-in defaults
 */
class ScheduleService
{
    public function __construct(
        private readonly ShiftCalculator $calculator,
        private readonly CompanyContext $context,
    ) {}

    /** Persisted snapshot for the date (created on first use). Later assignment changes never touch it. */
    public function forDate(Employee $employee, string $workDate): AttendanceSchedule
    {
        $existing = $this->find($employee, $workDate);
        if ($existing) {
            return $existing;
        }

        $schedule = $this->build($employee, $workDate);

        try {
            $schedule->save();
        } catch (UniqueConstraintViolationException) {
            return $this->find($employee, $workDate) ?? throw new \RuntimeException('Schedule race could not be resolved.');
        }

        return $schedule;
    }

    /** The stored snapshot when there is one, otherwise a fresh (unsaved) preview. Safe for read endpoints. */
    public function peek(Employee $employee, string $workDate): AttendanceSchedule
    {
        return $this->find($employee, $workDate) ?? $this->build($employee, $workDate);
    }

    /**
     * Which work date a moment belongs to. Normally the local calendar date, except while an
     * overnight shift that started yesterday is still running and has no attendance yet.
     */
    public function workDateFor(Employee $employee, CarbonInterface $moment): string
    {
        $local = $this->context->toLocal($moment);
        $today = $local->toDateString();
        $yesterday = $local->subDay()->toDateString();

        $prior = $this->peek($employee, $yesterday);
        $carriesOver = $prior->is_working_day
            && $prior->expected_end_at !== null
            && $this->context->toLocal($prior->expected_start_at)->toDateString() !== $this->context->toLocal($prior->expected_end_at)->toDateString()
            && $this->context->toLocal($prior->expected_end_at)->toDateString() === $today
            && $moment->getTimestamp() < $prior->expected_end_at->getTimestamp();

        if ($carriesOver) {
            $alreadyRecorded = $prior->exists && Attendance::query()
                ->where('employee_id', $employee->id)
                ->where('attendance_schedule_id', $prior->id)
                ->exists();

            if (! $alreadyRecorded) {
                return $yesterday;
            }
        }

        return $today;
    }

    public function build(Employee $employee, string $workDate): AttendanceSchedule
    {
        $tz = $this->context->timezone();
        $date = CarbonImmutable::parse($workDate, $tz);
        $dateString = $date->toDateString();

        $assignment = $employee->assignmentOn($dateString);
        $assignment?->loadMissing(['shift.breaks', 'workLocation.attendancePolicy']);

        $shift = $this->resolveShift($employee, $dateString) ?? $assignment?->shift;
        $location = $assignment?->workLocation;
        $policy = $location?->attendancePolicy ?? $this->defaultPolicy();
        $holiday = Holiday::query()
            ->where('company_id', $employee->company_id)
            ->whereDate('date', $dateString)
            ->first();

        $calendarWorks = $this->calendarAllowsWork($employee, $dateString, $date->dayOfWeekIso);
        $dayType = match (true) {
            $holiday !== null => DayType::Holiday,
            ! $calendarWorks => DayType::RestDay,
            default => DayType::Workday,
        };

        $start = $end = null;
        if ($shift) {
            [$start, $end] = $this->calculator->window(
                $dateString,
                (string) $shift->start_time,
                (string) $shift->end_time,
                $tz,
            );
        }

        return new AttendanceSchedule([
            'employee_id' => $employee->id,
            'work_date' => $dateString,
            'shift_id' => $shift?->id,
            'work_location_id' => $location?->id,
            'attendance_policy_id' => $policy->id,
            'holiday_id' => $holiday?->id,
            'is_working_day' => $dayType === DayType::Workday && $shift !== null,
            'day_type' => $dayType,
            'timezone' => $tz,
            'shift_name' => $shift?->name,
            'expected_start_at' => $start,
            'expected_end_at' => $end,
            'grace_late_minutes' => $shift?->grace_late_minutes ?? 0,
            'grace_early_leave_minutes' => $shift?->grace_early_leave_minutes ?? 0,
            'break_minutes' => $shift?->break_minutes ?? 0,
            'expected_working_minutes' => $shift?->working_minutes ?? 0,
            'location_name' => $location?->name,
            'location_latitude' => $location?->latitude,
            'location_longitude' => $location?->longitude,
            'location_radius_meters' => $location?->radius_meters,
            'require_gps' => $policy->require_gps,
            'geofence_mode' => $policy->geofence_mode,
            'require_selfie' => $policy->require_selfie,
            'max_gps_accuracy_meters' => $policy->max_gps_accuracy_meters,
            'reject_mock_location' => $policy->reject_mock_location,
            'offline_max_age_minutes' => $policy->offline_max_age_minutes,
        ]);
    }

    private function find(Employee $employee, string $workDate): ?AttendanceSchedule
    {
        return AttendanceSchedule::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $workDate)
            ->first();
    }

    private function resolveShift(Employee $employee, string $date): ?Shift
    {
        $override = ShiftAssignment::query()
            ->where('employee_id', $employee->id)
            ->where('start_date', '<=', $date)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $date))
            ->orderByDesc('start_date')->orderByDesc('id')
            ->first();

        return $override?->shift;
    }

    private function calendarAllowsWork(Employee $employee, string $date, int $isoDay): bool
    {
        $assigned = WorkingCalendarAssignment::query()
            ->where('employee_id', $employee->id)
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->orderByDesc('effective_from')->orderByDesc('id')
            ->first();

        $calendar = $assigned?->calendar
            ?? WorkingCalendar::query()->where('company_id', $employee->company_id)->where('is_default', true)->first();

        if (! $calendar) {
            return $isoDay <= 5; // no calendar configured: Monday-Friday
        }

        $calendar->loadMissing('days');

        return $calendar->isWorkingDay($isoDay);
    }

    private function defaultPolicy(): AttendancePolicy
    {
        $policy = AttendancePolicy::query()
            ->where('company_id', $this->context->id())
            ->where('is_default', true)
            ->first();

        return $policy ?? new AttendancePolicy([
            'require_gps' => true,
            'geofence_mode' => GeofenceMode::Enforce,
            'require_selfie' => false,
            'max_gps_accuracy_meters' => 100,
            'reject_mock_location' => true,
            'offline_max_age_minutes' => 720,
        ]);
    }
}
