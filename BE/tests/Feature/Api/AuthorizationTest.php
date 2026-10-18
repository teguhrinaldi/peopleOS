<?php

namespace Tests\Feature\Api;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\AttendanceEvent;
use App\Models\AttendanceSchedule;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Services\Attendance\ScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\WorkplaceFixtures;
use Tests\TestCase;

/** Permission enforcement and IDOR protection: an id in a URL or body never widens access. */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase, WorkplaceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkplace();
        Storage::fake('local');
    }

    private function attendanceFor(Employee $employee, string $date = '2026-09-29'): Attendance
    {
        $schedule = app(ScheduleService::class)->forDate($employee, $date);

        return Attendance::query()->create([
            'employee_id' => $employee->id, 'attendance_schedule_id' => $schedule->id, 'work_date' => $date,
            'status' => AttendanceStatus::CheckedOut, 'check_in_at' => "{$date} 01:00:00", 'check_out_at' => "{$date} 10:00:00",
        ]);
    }

    public function test_employee_cannot_list_or_open_other_employees(): void
    {
        [$alice] = $this->makeStaff();
        [, $bob] = $this->makeStaff();
        $this->actingAsApi($alice);

        $this->getJson('/api/v1/employees')->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
        $this->getJson("/api/v1/employees/{$bob->id}")->assertForbidden();
        $this->getJson('/api/v1/employees/me')->assertOk()->assertJsonPath('data.id', $alice->employee->id);
    }

    public function test_employee_can_open_their_own_record_by_id(): void
    {
        [$alice, $aliceEmployee] = $this->makeStaff();
        $this->actingAsApi($alice)->getJson("/api/v1/employees/{$aliceEmployee->id}")->assertOk();
    }

    public function test_manager_sees_only_direct_reports_and_hr_sees_everyone(): void
    {
        [$manager, $managerEmployee] = $this->makeStaff('manager');
        [, $report] = $this->makeStaff('employee', [], ['manager_id' => $managerEmployee->id]);
        [, $stranger] = $this->makeStaff();
        [$hr] = $this->makeStaff('hr');

        $this->actingAsApi($manager);
        $ids = collect($this->getJson('/api/v1/employees')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($report->id));
        $this->assertTrue($ids->contains($managerEmployee->id));
        $this->assertFalse($ids->contains($stranger->id));
        $this->getJson("/api/v1/employees/{$report->id}")->assertOk();
        $this->getJson("/api/v1/employees/{$stranger->id}")->assertForbidden();

        $this->actingAsApi($hr);
        $this->assertGreaterThanOrEqual(4, count($this->getJson('/api/v1/employees')->json('data')));
        $this->getJson("/api/v1/employees/{$stranger->id}")->assertOk();
    }

    public function test_a_report_who_moves_away_is_no_longer_visible_to_the_old_manager(): void
    {
        [$manager, $managerEmployee] = $this->makeStaff('manager');
        [, $report] = $this->makeStaff('employee', [], ['manager_id' => $managerEmployee->id]);

        EmployeeAssignment::query()->where('employee_id', $report->id)->update(['effective_to' => '2026-01-31']);
        EmployeeAssignment::query()->create([
            'employee_id' => $report->id, 'department_id' => $this->department->id, 'position_id' => $this->position->id,
            'manager_id' => null, 'effective_from' => '2026-02-01',
        ]);

        $this->actingAsApi($manager)->getJson("/api/v1/employees/{$report->id}")->assertForbidden();
    }

    public function test_organization_reference_data_needs_permission(): void
    {
        [$employee] = $this->makeStaff();
        [$hr] = $this->makeStaff('hr');

        $this->actingAsApi($employee)->getJson('/api/v1/organization/departments')->assertForbidden();
        $this->getJson('/api/v1/shifts')->assertForbidden();

        $this->actingAsApi($hr)->getJson('/api/v1/organization/departments')->assertOk()->assertJsonPath('data.0.name', 'Marketing');
        $this->getJson('/api/v1/shifts')->assertOk();
    }

    public function test_manager_role_does_not_inherit_hr_permissions(): void
    {
        [$manager] = $this->makeStaff('manager');

        $this->assertFalse($manager->hasPermission('employee.create'));
        $this->assertFalse($manager->hasPermission('employee.edit'));
        $this->assertFalse($manager->hasPermission('attendance.correct'));
        $this->assertFalse($manager->hasPermission('payroll.manage'));
        $this->assertFalse($manager->hasPermission('settings.manage'));
        $this->assertTrue($manager->hasPermission('attendance.approve_team'));

        $this->actingAsApi($manager)->getJson('/api/v1/organization/company')->assertOk();
        $this->getJson('/api/v1/attendance?scope=all')->assertForbidden();
    }

    public function test_attendance_history_is_always_scoped_to_the_caller(): void
    {
        [$alice, $aliceEmployee] = $this->makeStaff();
        [, $bob] = $this->makeStaff();
        $mine = $this->attendanceFor($aliceEmployee);
        $this->attendanceFor($bob);

        $this->actingAsApi($alice);
        $rows = $this->getJson('/api/v1/attendance')->assertOk()->json('data');
        $this->assertSame([$mine->id], collect($rows)->pluck('id')->all());

        // A guessed employee_id must not widen the scope.
        $rows = $this->getJson("/api/v1/attendance?employee_id={$bob->id}")->assertOk()->json('data');
        $this->assertSame([], $rows);

        $this->getJson('/api/v1/attendance?scope=all')->assertForbidden();
        $this->getJson('/api/v1/attendance?scope=team')->assertForbidden();
    }

    public function test_employee_cannot_read_another_employees_attendance_record(): void
    {
        [$alice] = $this->makeStaff();
        [, $bob] = $this->makeStaff();
        $bobRecord = $this->attendanceFor($bob);

        $this->actingAsApi($alice)->getJson("/api/v1/attendance/{$bobRecord->id}")->assertForbidden();
    }

    public function test_manager_can_read_team_attendance_but_not_a_strangers(): void
    {
        [$manager, $managerEmployee] = $this->makeStaff('manager');
        [, $report] = $this->makeStaff('employee', [], ['manager_id' => $managerEmployee->id]);
        [, $stranger] = $this->makeStaff();
        $teamRecord = $this->attendanceFor($report);
        $otherRecord = $this->attendanceFor($stranger);

        $this->actingAsApi($manager);
        $this->getJson("/api/v1/attendance/{$teamRecord->id}")->assertOk();
        $this->getJson("/api/v1/attendance/{$otherRecord->id}")->assertForbidden();

        $ids = collect($this->getJson('/api/v1/attendance?scope=team')->assertOk()->json('data'))->pluck('id');
        $this->assertSame([$teamRecord->id], $ids->all());
    }

    public function test_selfie_is_private_to_the_owner_and_authorised_viewers(): void
    {
        [$alice, $aliceEmployee] = $this->makeStaff();
        [$bobUser, $bob] = $this->makeStaff();
        [$hr] = $this->makeStaff('hr');
        $attendance = $this->attendanceFor($aliceEmployee);
        $file = UploadedFile::fake()->image('s.jpg');
        $path = $file->store('attendance-selfies/1', 'local');
        $event = AttendanceEvent::query()->create([
            'attendance_id' => $attendance->id, 'employee_id' => $aliceEmployee->id, 'type' => 'CHECK_IN',
            'idempotency_key' => 'selfie-owner-1', 'occurred_at' => now(), 'received_at' => now(), 'selfie_path' => $path,
        ]);

        $this->getJson("/api/v1/attendance-events/{$event->id}/selfie")->assertStatus(401);
        $this->actingAsApi($bobUser)->getJson("/api/v1/attendance-events/{$event->id}/selfie")->assertForbidden();
        $this->actingAsApi($alice)->get("/api/v1/attendance-events/{$event->id}/selfie")->assertOk()->assertHeader('Cache-Control');
        $this->actingAsApi($hr)->get("/api/v1/attendance-events/{$event->id}/selfie")->assertOk();

        // The path is not part of any JSON representation.
        $json = $this->actingAsApi($alice)->getJson("/api/v1/attendance/{$attendance->id}")->getContent();
        $this->assertStringNotContainsString($path, $json);
        $this->assertStringNotContainsString('attendance-selfies', $json);
    }

    public function test_unknown_ids_return_404_not_a_500(): void
    {
        [$hr] = $this->makeStaff('hr');
        $this->actingAsApi($hr)->getJson('/api/v1/employees/999999')->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
        $this->getJson('/api/v1/attendance/999999')->assertStatus(404);
    }

    public function test_schedule_rows_are_not_exposed_across_employees_through_today(): void
    {
        [$alice] = $this->makeStaff();
        [, $bob] = $this->makeStaff();
        $this->at('2026-09-30 08:00');
        app(ScheduleService::class)->forDate($bob, '2026-09-30');

        $this->actingAsApi($alice)->getJson('/api/v1/attendance/today')->assertOk();
        $this->assertSame(0, AttendanceSchedule::query()->where('employee_id', $alice->employee->id)->count());
    }
}
