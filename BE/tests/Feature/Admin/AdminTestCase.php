<?php

namespace Tests\Feature\Admin;

use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceEvent;
use App\Models\AttendanceSchedule;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Services\Leave\LeaveService;
use App\Services\Leave\LeaveSetupService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\WorkplaceFixtures;
use Tests\TestCase;

/**
 * Base for web-admin feature tests: seeded roles, a small company, Vite stubbed out,
 * and a hard stop if the suite is ever pointed at the shared development database.
 */
abstract class AdminTestCase extends TestCase
{
    use RefreshDatabase, WorkplaceFixtures;

    protected function beforeRefreshingDatabase(): void
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');
        if (! str_contains($database, 'test')) {
            throw new RuntimeException("Refusing to migrate '{$database}': run the admin tests against a *_test database.");
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->setUpWorkplace();
        $this->at('2026-09-30 10:00'); // Wednesday, 03:00 UTC
    }

    /** @return array{0: User, 1: Employee} */
    protected function signIn(string $role, array $employee = [], array $assignment = []): array
    {
        [$user, $emp] = $this->makeStaff($role, $employee, $assignment);
        $this->actingAs($user);

        return [$user, $emp];
    }

    /** A person without a login. */
    protected function person(string $name, array $employee = [], array $assignment = []): Employee
    {
        $n = random_int(1000, 9999).random_int(10, 99);
        $emp = Employee::query()->create(array_merge([
            'company_id' => $this->company->id, 'employee_number' => "X-{$n}", 'full_name' => $name,
            'email' => "x{$n}@test.example", 'join_date' => '2025-01-01', 'employment_type' => 'permanent', 'status' => 'ACTIVE',
        ], $employee));

        $emp->assignments()->create(array_merge([
            'branch_id' => $this->branch->id, 'department_id' => $this->department->id, 'position_id' => $this->position->id,
            'work_location_id' => $this->location->id, 'shift_id' => $this->shift->id, 'effective_from' => $emp->join_date->toDateString(),
        ], $assignment));

        return $emp->fresh();
    }

    /** An active leave type with a default policy (10 days a year, no notice, reason optional). */
    protected function leaveType(string $code = 'ANNUAL', string $name = 'Annual Leave', array $policy = [], bool $paid = true): LeaveType
    {
        return app(LeaveSetupService::class)->createType(
            ['code' => $code, 'name' => $name, 'is_paid' => $paid, 'color' => 'blue', 'is_active' => true, 'sort_order' => 0],
            $policy + ['annual_allowance' => '10.0', 'prorate_first_year' => false, 'carry_over_max' => '0', 'allow_negative' => false, 'max_negative_days' => '0',
                'allow_half_day' => true, 'allow_overlap' => false, 'min_notice_days' => 0, 'max_past_days' => 0, 'max_consecutive_days' => null, 'requires_reason' => false],
            $this->filer(),
        );
    }

    /** Submits leave for the employee through LeaveService, filed by an HR user (policy: leave.manage). */
    protected function leaveFor(Employee $employee, LeaveType $type, string $start = '2026-10-05', ?string $end = null, ?string $half = null, string $reason = 'Family event'): LeaveRequest
    {
        return app(LeaveService::class)->create($employee, $this->filer(), [
            'leave_type_id' => $type->id, 'start_date' => $start, 'end_date' => $end ?? $start, 'half_day' => $half, 'reason' => $reason,
        ]);
    }

    private ?User $filer = null;

    private function filer(): User
    {
        return $this->filer ??= $this->makeStaff('hr')[0];
    }

    /** A pending correction asking to set check-out to 17:00 local on the attendance's work date. */
    protected function correctionFor(Attendance $attendance, ?User $requestedBy = null): AttendanceCorrection
    {
        $day = $attendance->work_date->toDateString();

        return AttendanceCorrection::query()->create([
            'employee_id' => $attendance->employee_id, 'attendance_id' => $attendance->id, 'attendance_schedule_id' => $attendance->attendance_schedule_id,
            'work_date' => $day, 'requested_check_out_at' => CarbonImmutable::parse("{$day} 17:00", 'Asia/Jakarta')->utc(),
            'reason' => 'Forgot to check out', 'status' => 'PENDING', 'requested_by' => $requestedBy?->id,
            'original_snapshot' => ['status' => $attendance->status->value, 'check_in_at' => $attendance->check_in_at?->toIso8601String(), 'check_out_at' => null],
        ]);
    }

    /** A pending two-hour overtime request on a local date (17:00-19:00). */
    protected function overtimeFor(Employee $employee, string $day = '2026-09-29', int $minutes = 120): OvertimeRequest
    {
        $start = CarbonImmutable::parse("{$day} 17:00", 'Asia/Jakarta')->utc();

        return OvertimeRequest::query()->create([
            'employee_id' => $employee->id, 'work_date' => $day, 'start_at' => $start, 'end_at' => $start->addMinutes($minutes),
            'requested_minutes' => $minutes, 'reason' => 'Launch prep', 'status' => 'PENDING',
        ]);
    }

    /** @param array<string, mixed> $extra */
    protected function event(Attendance $attendance, string $type = 'CHECK_IN', array $extra = []): AttendanceEvent
    {
        return AttendanceEvent::query()->create(array_merge([
            'attendance_id' => $attendance->id, 'employee_id' => $attendance->employee_id, 'type' => $type,
            'idempotency_key' => 'k-'.$attendance->id.'-'.$type.'-'.random_int(1, 999999),
            'occurred_at' => $attendance->check_in_at, 'received_at' => $attendance->check_in_at,
            'latitude' => -6.9219, 'longitude' => 107.6072, 'accuracy_meters' => 8, 'distance_meters' => 14, 'inside_geofence' => true,
        ], $extra));
    }

    /** Attendance row for a local work date; `$late` minutes after the 08:00 shift start. */
    protected function attendance(Employee $employee, string $workDate, int $late = 0, string $status = 'CHECKED_IN', bool $review = false, array $schedule = []): Attendance
    {
        $start = CarbonImmutable::parse("{$workDate} 08:00", 'Asia/Jakarta')->utc();
        $schedule = AttendanceSchedule::query()->create($schedule + [
            'employee_id' => $employee->id, 'work_date' => $workDate, 'shift_id' => $this->shift->id, 'is_working_day' => true,
            'day_type' => 'workday', 'timezone' => 'Asia/Jakarta', 'shift_name' => $this->shift->name,
            'expected_start_at' => $start, 'expected_end_at' => $start->addHours(9),
            'work_location_id' => $this->location->id, 'location_name' => $this->location->name,
            'location_latitude' => -6.9218, 'location_longitude' => 107.6071, 'location_radius_meters' => 150,
        ]);

        return Attendance::query()->create([
            'employee_id' => $employee->id, 'attendance_schedule_id' => $schedule->id, 'work_date' => $workDate, 'status' => $status,
            'check_in_at' => $start->addMinutes($late), 'is_late' => $late > 10, 'late_minutes' => max(0, $late - 10),
            'needs_review' => $review,
        ]);
    }
}
