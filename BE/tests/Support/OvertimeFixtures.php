<?php

namespace Tests\Support;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\OvertimeRule;
use App\Services\Attendance\ScheduleService;
use App\Support\Settings;
use Carbon\CarbonImmutable;

/**
 * Overtime engine fixtures on top of WorkplaceFixtures + PayrollFixtures (shift 08:00-17:00 Asia/Jakarta).
 * Demo rule: basic / 173; workday 1st hour x1.5 then x2; rest day x2 (8 h) then x3; holiday x3. Not statutory.
 */
trait OvertimeFixtures
{
    protected OvertimeRule $rule;

    protected function setUpOvertimeEngine(bool $enabled = true): void
    {
        app(Settings::class)->set(['overtime.engine_enabled' => $enabled]);
        $this->rule = OvertimeRule::query()->create([
            'company_id' => $this->company->id, 'name' => 'Demo overtime rule', 'effective_from' => '2026-01-01',
            'rate_basis' => 'BASIC', 'rate_divisor' => '173',
            'multiplier_tiers' => [
                'workday' => [['up_to_hours' => 1, 'multiplier' => '1.5'], ['multiplier' => '2']],
                'rest_day' => [['up_to_hours' => 8, 'multiplier' => '2'], ['multiplier' => '3']],
                'holiday' => [['multiplier' => '3']],
            ],
        ]);
    }

    /** Attendance from the scheduled start to $outLocal (Asia/Jakarta wall clock) on $date. */
    protected function worked(Employee $employee, string $date, string $outLocal): Attendance
    {
        $schedule = app(ScheduleService::class)->forDate($employee, $date);

        return Attendance::query()->create([
            'employee_id' => $employee->id, 'attendance_schedule_id' => $schedule->id, 'work_date' => $date,
            'status' => 'CHECKED_OUT',
            'check_in_at' => $schedule->expected_start_at ?? CarbonImmutable::parse("{$date} 08:00", 'Asia/Jakarta')->utc(),
            'check_out_at' => CarbonImmutable::parse("{$date} {$outLocal}", 'Asia/Jakarta')->utc(),
        ]);
    }

    protected function overtime(Employee $employee, string $date, int $minutes, string $startLocal = '17:00', string $status = 'APPROVED', ?int $approved = null): OvertimeRequest
    {
        $start = CarbonImmutable::parse("{$date} {$startLocal}", 'Asia/Jakarta')->utc();

        return OvertimeRequest::query()->create([
            'employee_id' => $employee->id, 'work_date' => $date, 'start_at' => $start, 'end_at' => $start->addMinutes($minutes),
            'requested_minutes' => $minutes, 'approved_minutes' => $status === 'APPROVED' ? ($approved ?? $minutes) : null,
            'reason' => 'Release deadline', 'status' => $status,
            'payroll_status' => $status === 'APPROVED' ? OvertimeRequest::PAYROLL_PENDING : OvertimeRequest::PAYROLL_NONE,
        ]);
    }
}
