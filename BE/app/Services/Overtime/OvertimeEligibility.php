<?php

namespace App\Services\Overtime;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\OvertimeRequest;
use App\Services\Attendance\ScheduleService;
use App\Support\Settings;

/**
 * Requested vs approved vs VERIFIED vs eligible minutes of one approved overtime request.
 *
 *   attendance with check-in AND check-out on the work date (not REJECTED):
 *       verified = OvertimeVerifier (approved window ∩ check-in/out, minus the scheduled shift)
 *       eligible = min(approved, verified)
 *   no complete attendance:
 *       Settings overtime.require_attendance_verification = true  (default) -> eligible 0   (NO_ATTENDANCE)
 *       Settings overtime.require_attendance_verification = false           -> eligible = approved (NOT_REQUIRED)
 *
 * Day type: frozen on the request at approval; otherwise the employee's schedule for the date
 * (holiday > rest day by working calendar > workday).
 */
class OvertimeEligibility
{
    public function __construct(
        private readonly OvertimeVerifier $verifier,
        private readonly ScheduleService $schedules,
        private readonly Settings $settings,
    ) {}

    /** @return array{approved: int, verified: ?int, status: string, eligible: int, day_type: string} */
    public function evaluate(OvertimeRequest $request): array
    {
        $approved = (int) ($request->approved_minutes ?? 0);
        $dayType = $request->day_type ?? $this->dayType($request);

        $attendance = Attendance::query()
            ->with('schedule')
            ->where('employee_id', $request->employee_id)
            ->whereDate('work_date', $request->work_date->toDateString())
            ->whereNotNull('check_in_at')
            ->whereNotNull('check_out_at')
            ->where('status', '!=', AttendanceStatus::Rejected->value)
            ->first();

        if (! $attendance) {
            $required = $this->settings->bool('overtime.require_attendance_verification');

            return [
                'approved' => $approved,
                'verified' => null,
                'status' => $required ? 'NO_ATTENDANCE' : 'NOT_REQUIRED',
                'eligible' => $required ? 0 : $approved,
                'day_type' => $dayType,
            ];
        }

        $schedule = $attendance->schedule;
        $regular = $dayType === 'workday' && $schedule?->expected_start_at && $schedule?->expected_end_at;
        $verified = $this->verifier->verifiedMinutes(
            $approved,
            $request->start_at,
            $attendance->check_in_at,
            $attendance->check_out_at,
            $regular ? $schedule->expected_start_at : null,
            $regular ? $schedule->expected_end_at : null,
        );

        return [
            'approved' => $approved,
            'verified' => $verified,
            'status' => $this->verifier->status($approved, $verified),
            'eligible' => min($approved, $verified),
            'day_type' => $dayType,
        ];
    }

    public function dayType(OvertimeRequest $request): string
    {
        $request->loadMissing('employee');

        return $this->schedules->peek($request->employee, $request->work_date->toDateString())->day_type?->value ?? 'workday';
    }
}
