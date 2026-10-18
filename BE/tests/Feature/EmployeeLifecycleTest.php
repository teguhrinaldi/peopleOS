<?php

namespace Tests\Feature;

use App\Enums\EmployeeStatus;
use App\Exceptions\ApiException;
use App\Models\Activity;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\EmployeeService;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkplaceFixtures;
use Tests\TestCase;

class EmployeeLifecycleTest extends TestCase
{
    use RefreshDatabase, WorkplaceFixtures;

    private EmployeeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkplace();
        $this->service = app(EmployeeService::class);
        $this->at('2026-09-30 09:00');
    }

    /** @return array<string, mixed> */
    private function data(array $overrides = []): array
    {
        return array_merge([
            'employee_number' => 'EMP-9001', 'full_name' => 'New Hire', 'email' => 'new.hire@test.example', 'join_date' => '2026-09-01',
            'employment_type' => 'permanent', 'department_id' => $this->department->id, 'position_id' => $this->position->id,
            'branch_id' => $this->branch->id, 'work_location_id' => $this->location->id, 'shift_id' => $this->shift->id,
        ], $overrides);
    }

    public function test_create_starts_pending_with_a_dated_assignment(): void
    {
        $employee = $this->service->create($this->data());

        $this->assertSame(EmployeeStatus::Pending, $employee->status);
        $this->assertSame(1, $employee->assignments()->count());
        $this->assertSame('2026-09-01', $employee->assignments()->first()->effective_from->toDateString());
        $this->assertTrue(Activity::query()->where('action', 'employee.created')->exists());
    }

    public function test_optional_login_is_created_with_the_requested_role(): void
    {
        $employee = $this->service->create($this->data(['create_login' => true, 'password' => 'Secret-123', 'role_slug' => 'manager', 'status' => 'ACTIVE']));

        $this->assertNotNull($employee->user_id);
        $this->assertTrue($employee->user->is_active);
        $this->assertTrue($employee->user->hasPermission('attendance.approve_team'));
        $this->assertFalse($employee->user->hasPermission('employee.create'));
    }

    public function test_a_login_for_a_pending_hire_stays_disabled_until_activation(): void
    {
        $employee = $this->service->create($this->data(['create_login' => true, 'password' => 'Secret-123']));
        $this->assertFalse($employee->user->is_active);

        $this->service->changeStatus($employee, EmployeeStatus::Active);
        $this->assertTrue($employee->user->fresh()->is_active);
    }

    public function test_duplicate_employee_number_and_email_are_rejected_by_the_database(): void
    {
        $this->service->create($this->data());

        try {
            $this->service->create($this->data(['email' => 'other@test.example']));
            $this->fail('duplicate employee number accepted');
        } catch (UniqueConstraintViolationException $e) {
            $this->assertStringContainsString('employees_company_id_employee_number_unique', $e->getMessage());
        }

        try {
            $this->service->create($this->data(['employee_number' => 'EMP-9002']));
            $this->fail('duplicate email accepted');
        } catch (UniqueConstraintViolationException $e) {
            $this->assertStringContainsString('employees_company_id_email_unique', $e->getMessage());
        }

        $this->assertSame(1, Employee::query()->count());
    }

    public function test_duplicate_login_email_is_rejected(): void
    {
        [$user] = $this->makeStaff();

        $this->expectException(UniqueConstraintViolationException::class);
        $this->service->create($this->data(['create_login' => true, 'login_email' => $user->email, 'password' => 'Secret-123']));
    }

    public function test_status_transitions_follow_the_lifecycle(): void
    {
        $employee = $this->service->create($this->data());

        $this->service->changeStatus($employee, EmployeeStatus::Active);
        $this->service->changeStatus($employee, EmployeeStatus::Inactive);
        $this->service->changeStatus($employee, EmployeeStatus::Active);
        $this->assertSame(EmployeeStatus::Active, $employee->fresh()->status);

        $pending = $this->service->create($this->data(['employee_number' => 'EMP-9003', 'email' => 'p@test.example']));
        try {
            $this->service->changeStatus($pending, EmployeeStatus::Inactive);
            $this->fail('PENDING -> INACTIVE must be refused');
        } catch (ApiException $e) {
            $this->assertSame('INVALID_STATUS_TRANSITION', $e->errorCode);
        }
    }

    public function test_termination_is_terminal_closes_the_assignment_disables_login_and_revokes_tokens(): void
    {
        [$user, $employee] = $this->makeStaff();
        $user->createToken('mobile');

        $this->service->terminate($employee, '2026-10-15', 'Resigned');

        $employee->refresh();
        $this->assertSame(EmployeeStatus::Terminated, $employee->status);
        $this->assertSame('2026-10-15', $employee->assignments()->first()->effective_to->toDateString());
        $this->assertFalse($user->fresh()->is_active);
        $this->assertSame(0, $user->tokens()->count());

        foreach ([
            fn () => $this->service->changeStatus($employee, EmployeeStatus::Active),
            fn () => $this->service->update($employee, ['full_name' => 'Renamed']),
            fn () => $this->service->reassign($employee, ['department_id' => $this->department->id, 'position_id' => $this->position->id], '2026-11-01'),
            fn () => $this->service->terminate($employee, '2026-10-20', 'again'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('a terminated employee accepted a change');
            } catch (ApiException $e) {
                $this->assertContains($e->errorCode, ['INVALID_STATUS_TRANSITION', 'EMPLOYEE_TERMINATED']);
            }
        }
    }

    public function test_reassign_keeps_history_instead_of_overwriting(): void
    {
        [, $employee] = $this->makeStaff();
        $other = Department::query()->create(['company_id' => $this->company->id, 'code' => 'FIN', 'name' => 'Finance']);

        $this->service->reassign($employee, ['department_id' => $other->id, 'position_id' => $this->position->id, 'shift_id' => $this->shift->id], '2026-10-01');

        $history = EmployeeAssignment::query()->where('employee_id', $employee->id)->orderBy('effective_from')->get();
        $this->assertCount(2, $history);
        $this->assertSame($this->department->id, $history[0]->department_id);
        $this->assertSame('2026-09-30', $history[0]->effective_to->toDateString());
        $this->assertSame($other->id, $history[1]->department_id);
        $this->assertNull($history[1]->effective_to);

        // Yesterday's answer is still Marketing; the day after the change it is Finance.
        $this->assertSame($this->department->id, $employee->assignmentOn('2026-09-15')->department_id);
        $this->assertSame($other->id, $employee->assignmentOn('2026-10-02')->department_id);
    }

    public function test_reassign_cannot_start_before_the_previous_assignment(): void
    {
        [, $employee] = $this->makeStaff();

        $this->expectException(ApiException::class);
        $this->service->reassign($employee, ['department_id' => $this->department->id, 'position_id' => $this->position->id], '2025-01-01');
    }

    public function test_manager_cannot_be_self_or_a_circular_reference(): void
    {
        [, $boss] = $this->makeStaff();
        [, $mid] = $this->makeStaff('employee', [], ['manager_id' => $boss->id]);
        $base = ['department_id' => $this->department->id, 'position_id' => $this->position->id];

        foreach ([[$boss, $boss->id], [$boss, $mid->id]] as [$employee, $managerId]) {
            try {
                $this->service->reassign($employee, $base + ['manager_id' => $managerId], '2026-10-01');
                $this->fail('invalid manager accepted');
            } catch (ApiException $e) {
                $this->assertSame('INVALID_MANAGER', $e->errorCode);
            }
        }
    }

    public function test_delete_is_only_for_never_used_pending_employees(): void
    {
        $pending = $this->service->create($this->data());
        [, $active] = $this->makeStaff();

        try {
            $this->service->delete($active);
            $this->fail('active employee deleted');
        } catch (ApiException $e) {
            $this->assertSame('EMPLOYEE_DELETE_RESTRICTED', $e->errorCode);
        }

        $this->service->delete($pending);
        $this->assertSoftDeleted('employees', ['id' => $pending->id]);
        $this->assertSame(1, Employee::withTrashed()->where('id', $pending->id)->count(), 'the row is kept');
    }

    public function test_deleting_an_employee_with_attendance_history_is_refused_even_if_pending(): void
    {
        [$user, $employee] = $this->makeStaff();
        $this->at('2026-09-30 08:00');
        $this->actingAsApi($user)->postJson('/api/v1/attendance/check-in', $this->capture('life-key-0001'))->assertCreated();
        $employee->update(['status' => EmployeeStatus::Pending->value]);

        $this->expectException(ApiException::class);
        $this->service->delete($employee->fresh());
    }

    public function test_master_data_in_use_cannot_be_physically_deleted(): void
    {
        $this->makeStaff();

        $this->expectException(QueryException::class);
        DB::table('departments')->where('id', $this->department->id)->delete(); // RESTRICT: history is sacred
    }

    public function test_updates_are_audited_with_old_and_new_values(): void
    {
        [$actor] = $this->makeStaff('hr');
        [, $employee] = $this->makeStaff();
        $this->actingAs($actor);
        $originalName = $employee->full_name;

        $this->service->update($employee, ['full_name' => 'Renamed Person'], $actor);

        $activity = Activity::query()->where('action', 'employee.updated')->firstOrFail();
        $this->assertSame($actor->id, $activity->actor_id);
        $this->assertSame('Renamed Person', $activity->new_values['full_name']);
        $this->assertSame($originalName, $activity->old_values['full_name']);
        $this->assertArrayNotHasKey('updated_at', $activity->new_values);
    }

    public function test_changing_the_employee_email_keeps_a_matching_login_email_in_step(): void
    {
        [$user, $employee] = $this->makeStaff();

        $this->service->update($employee, ['email' => 'moved@test.example', 'full_name' => 'Moved Person']);

        $user->refresh();
        $this->assertSame('moved@test.example', $user->email);
        $this->assertSame('Moved Person', $user->name);
    }

    public function test_a_separate_login_email_is_not_overwritten(): void
    {
        [$user, $employee] = $this->makeStaff();
        $user->update(['email' => 'private-login@test.example']);

        $this->service->update($employee->fresh(), ['email' => 'work@test.example']);

        $this->assertSame('private-login@test.example', $user->fresh()->email);
    }

    public function test_audit_log_redacts_secrets(): void
    {
        $activity = app(ActivityLogger::class)->log('test.secret', User::query()->create([
            'name' => 'X', 'email' => 'x@test.example', 'password' => 'password',
        ]), null, ['password' => 'hunter2', 'nested' => ['token' => 'abc', 'ok' => 'fine'], 'name' => 'visible']);

        $this->assertSame('[redacted]', $activity->new_values['password']);
        $this->assertSame('[redacted]', $activity->new_values['nested']['token']);
        $this->assertSame('fine', $activity->new_values['nested']['ok']);
        $this->assertSame('visible', $activity->new_values['name']);
    }
}
