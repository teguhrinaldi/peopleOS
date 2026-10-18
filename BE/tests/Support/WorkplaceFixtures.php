<?php

namespace Tests\Support;

use App\Enums\EmployeeStatus;
use App\Enums\GeofenceMode;
use App\Models\AttendancePolicy;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Position;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkingCalendar;
use App\Models\WorkLocation;
use App\Services\Attendance\ShiftCalculator;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

/**
 * Small explicit fixtures (the heavy DemoSeeder is never used in tests).
 * Default site: lat -6.9218 / lng 107.6071, radius 150 m, Asia/Jakarta, Mon-Fri.
 */
trait WorkplaceFixtures
{
    protected Company $company;

    protected Branch $branch;

    protected Department $department;

    protected Position $position;

    protected Shift $shift;

    protected WorkLocation $location;

    protected AttendancePolicy $policy;

    private int $employeeSeq = 0;

    protected function setUpWorkplace(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->company = Company::query()->create(['name' => 'Test Co', 'timezone' => 'Asia/Jakarta']);
        $this->branch = Branch::query()->create(['company_id' => $this->company->id, 'code' => 'HO', 'name' => 'Head Office']);
        $this->department = Department::query()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'code' => 'MKT', 'name' => 'Marketing']);
        $this->position = Position::query()->create(['company_id' => $this->company->id, 'code' => 'STF', 'name' => 'Staff']);

        $calendar = WorkingCalendar::query()->create(['company_id' => $this->company->id, 'name' => 'Mon-Fri', 'is_default' => true]);
        foreach (range(1, 7) as $day) {
            $calendar->days()->create(['day_of_week' => $day, 'is_working_day' => $day <= 5]);
        }

        $this->policy = AttendancePolicy::query()->create([
            'company_id' => $this->company->id, 'name' => 'Standard', 'is_default' => true, 'require_gps' => true,
            'geofence_mode' => GeofenceMode::Enforce, 'require_selfie' => false, 'max_gps_accuracy_meters' => 100,
            'reject_mock_location' => true, 'offline_max_age_minutes' => 720,
        ]);

        $this->location = WorkLocation::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'attendance_policy_id' => $this->policy->id,
            'code' => 'HO', 'name' => 'Head Office', 'latitude' => -6.9218, 'longitude' => 107.6071, 'radius_meters' => 150,
        ]);

        $this->shift = $this->makeShift('REG', '08:00', '17:00', grace: 10, breakMinutes: 60);
    }

    protected function makeShift(string $code, string $start, string $end, int $grace = 0, int $breakMinutes = 0): Shift
    {
        $calc = new ShiftCalculator;
        $breaks = $breakMinutes ? [['duration_minutes' => $breakMinutes, 'is_paid' => false]] : [];

        return Shift::query()->create([
            'company_id' => $this->company->id, 'code' => $code, 'name' => $code.' shift', 'start_time' => $start, 'end_time' => $end,
            'crosses_midnight' => $calc->crossesMidnight($start, $end), 'grace_late_minutes' => $grace,
            'grace_early_leave_minutes' => $grace, 'break_minutes' => $breakMinutes,
            'working_minutes' => $calc->workingMinutes($start, $end, $breaks),
        ]);
    }

    /**
     * @param  array<string, mixed>  $employee
     * @param  array<string, mixed>  $assignment
     * @return array{0: User, 1: Employee}
     */
    protected function makeStaff(string $role = 'employee', array $employee = [], array $assignment = []): array
    {
        $n = ++$this->employeeSeq;

        $user = User::query()->create([
            'name' => "Person {$n}", 'email' => "person{$n}@test.example", 'password' => 'password', 'is_active' => true,
        ]);
        $user->assignRole($role);

        $emp = Employee::query()->create(array_merge([
            'company_id' => $this->company->id, 'user_id' => $user->id, 'employee_number' => sprintf('EMP-%04d', $n),
            'full_name' => "Person {$n}", 'email' => "person{$n}@test.example", 'join_date' => '2025-01-01',
            'employment_type' => 'permanent', 'status' => EmployeeStatus::Active->value,
        ], $employee));

        EmployeeAssignment::query()->create(array_merge([
            'employee_id' => $emp->id, 'branch_id' => $this->branch->id, 'department_id' => $this->department->id,
            'position_id' => $this->position->id, 'work_location_id' => $this->location->id, 'shift_id' => $this->shift->id,
            'effective_from' => $emp->join_date->toDateString(),
        ], $assignment));

        return [$user, $emp->fresh()];
    }

    /** Sign in as the user with a real Sanctum token for API calls. */
    protected function actingAsApi(User $user): static
    {
        Sanctum::actingAs($user, ['*']);

        return $this;
    }

    /** Freeze the clock at a wall-clock time in the company timezone. */
    protected function at(string $localDateTime): void
    {
        $this->travelTo(CarbonImmutable::parse($localDateTime, 'Asia/Jakarta'));
    }

    /** @return array<string, mixed> */
    protected function capture(string $key, float $lat = -6.9219, float $lng = 107.6072, array $extra = []): array
    {
        return array_merge([
            'idempotency_key' => $key, 'latitude' => $lat, 'longitude' => $lng, 'accuracy_meters' => 10,
        ], $extra);
    }
}
