<?php

namespace Tests\Feature\Admin;

use App\Enums\EmployeeStatus;
use App\Models\Activity;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeAssignment;

class EmployeeLifecycleWebTest extends AdminTestCase
{
    private function terminatePayload(array $overrides = []): array
    {
        return array_merge(['terminated_at' => '2026-09-29', 'reason' => 'Resigned', 'confirm' => 1], $overrides);
    }

    public function test_termination_sets_status_closes_the_assignment_disables_the_login_and_revokes_tokens(): void
    {
        [$hr] = $this->signIn('hr');
        [$user, $employee] = $this->makeStaff('employee');
        $user->createToken('mobile');
        $this->assertSame(1, $user->tokens()->count());

        $this->post("/admin/employees/{$employee->id}/terminate", $this->terminatePayload())->assertRedirect("/admin/employees/{$employee->id}");

        $employee->refresh();
        $this->assertSame(EmployeeStatus::Terminated, $employee->status);
        $this->assertSame('2026-09-29', $employee->terminated_at->toDateString());
        $this->assertSame('Resigned', $employee->termination_reason);
        $this->assertSame('2026-09-29', $employee->assignments()->first()->effective_to->toDateString());
        $this->assertFalse($user->fresh()->is_active);
        $this->assertSame(0, $user->tokens()->count());

        $log = Activity::query()->where('action', 'employee.terminated')->where('entity_id', $employee->id)->firstOrFail();
        $this->assertSame($hr->id, $log->actor_id);
        $this->assertSame('Resigned', $log->new_values['termination_reason']);
    }

    public function test_terminated_records_are_read_only_in_the_ui_and_on_the_server(): void
    {
        $this->signIn('hr');
        $employee = $this->person('Leaver Lou');
        $this->post("/admin/employees/{$employee->id}/terminate", $this->terminatePayload());

        $page = $this->get("/admin/employees/{$employee->id}")->assertOk();
        $page->assertSee('Employment ended')->assertSee('read-only')->assertDontSee('Terminate employment')->assertDontSee('Change assignment');

        $this->post("/admin/employees/{$employee->id}/terminate", $this->terminatePayload())->assertSessionHas('error');
        $this->post("/admin/employees/{$employee->id}/status", ['status' => 'ACTIVE'])->assertSessionHas('error');
        $this->post("/admin/employees/{$employee->id}/assignments", ['department_id' => $this->department->id, 'position_id' => $this->position->id, 'effective_from' => '2026-09-30'])
            ->assertSessionHas('error');

        $this->assertSame(EmployeeStatus::Terminated, $employee->fresh()->status);
        $this->assertSame(1, $employee->assignments()->count());
    }

    public function test_termination_validation(): void
    {
        $this->signIn('hr');
        $employee = $this->person('Stayer Sam', ['join_date' => '2025-01-01']);
        $url = "/admin/employees/{$employee->id}/terminate";

        $this->post($url, $this->terminatePayload(['terminated_at' => '2026-10-15']))->assertSessionHasErrors(['terminated_at' => 'The last working day cannot be in the future.']);
        $this->post($url, $this->terminatePayload(['terminated_at' => '2024-12-31']))->assertSessionHasErrors('terminated_at');
        $this->post($url, $this->terminatePayload(['reason' => '']))->assertSessionHasErrors('reason');
        $this->post($url, $this->terminatePayload(['confirm' => 0]))->assertSessionHasErrors('confirm');

        $this->assertSame(EmployeeStatus::Active, $employee->fresh()->status);
    }

    public function test_deactivate_and_activate_toggle_the_login(): void
    {
        $this->signIn('hr');
        [$user, $employee] = $this->makeStaff('employee');

        $this->post("/admin/employees/{$employee->id}/status", ['status' => 'INACTIVE'])->assertRedirect()->assertSessionHas('success');
        $this->assertSame(EmployeeStatus::Inactive, $employee->fresh()->status);
        $this->assertFalse($user->fresh()->is_active);

        $this->post("/admin/employees/{$employee->id}/status", ['status' => 'ACTIVE'])->assertSessionHas('success');
        $this->assertTrue($user->fresh()->is_active);

        $this->assertSame(2, Activity::query()->where('action', 'employee.status_changed')->count());
    }

    public function test_invalid_status_requests_are_refused(): void
    {
        $this->signIn('hr');
        $pending = $this->person('Pending Pete', ['status' => 'PENDING']);
        $employee = $this->person('Someone');

        $this->post("/admin/employees/{$employee->id}/status", ['status' => 'TERMINATED'])->assertSessionHasErrors('status');
        $this->post("/admin/employees/{$employee->id}/status", ['status' => 'PENDING'])->assertSessionHasErrors('status');
        $this->post("/admin/employees/{$pending->id}/status", ['status' => 'INACTIVE'])->assertSessionHas('error');
        $this->assertSame(EmployeeStatus::Pending, $pending->fresh()->status);
    }

    public function test_reassigning_keeps_history_and_closes_the_previous_assignment(): void
    {
        $this->signIn('hr');
        $employee = $this->person('Mover Max', ['join_date' => '2025-01-01']);
        $sales = Department::query()->create(['company_id' => $this->company->id, 'code' => 'SAL', 'name' => 'Sales Team']);

        $this->post("/admin/employees/{$employee->id}/assignments", [
            'department_id' => $sales->id, 'position_id' => $this->position->id, 'effective_from' => '2026-09-30', 'assignment_reason' => 'Transfer',
        ])->assertRedirect("/admin/employees/{$employee->id}?tab=employment")->assertSessionHas('success');

        $rows = EmployeeAssignment::query()->where('employee_id', $employee->id)->orderBy('effective_from')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('2026-09-29', $rows[0]->effective_to->toDateString());
        $this->assertNull($rows[1]->effective_to);
        $this->assertSame($sales->id, $rows[1]->department_id);
        $this->assertSame('Transfer', $rows[1]->reason);
        $this->assertTrue(Activity::query()->where('action', 'employee.reassigned')->where('entity_id', $employee->id)->exists());

        $this->get("/admin/employees/{$employee->id}?tab=employment")->assertOk()
            ->assertSee('Sales Team')->assertSee('Marketing')->assertSee('Transfer');
    }

    public function test_reassign_rejects_overlapping_dates_and_management_cycles(): void
    {
        $this->signIn('hr');
        [, $boss] = $this->makeStaff('manager');
        $report = $this->person('Report Rae', [], ['manager_id' => $boss->id]);
        $url = "/admin/employees/{$boss->id}/assignments";
        $base = ['department_id' => $this->department->id, 'position_id' => $this->position->id];

        $this->post($url, $base + ['effective_from' => '2025-01-01'])->assertSessionHasErrors('effective_from');
        $this->post($url, $base + ['effective_from' => '2026-09-30', 'manager_id' => $report->id])->assertSessionHasErrors('manager_id');
        $this->post($url, $base + ['effective_from' => '2026-09-30', 'manager_id' => $boss->id])->assertSessionHasErrors('manager_id');
        $this->assertSame(1, $boss->assignments()->count());
    }

    public function test_only_users_with_edit_permission_can_reassign(): void
    {
        [, $manager] = $this->signIn('manager');
        $report = $this->person('Report Rita', [], ['manager_id' => $manager->id]);

        $this->post("/admin/employees/{$report->id}/assignments", ['department_id' => $this->department->id, 'position_id' => $this->position->id, 'effective_from' => '2026-09-30'])
            ->assertForbidden();
    }

    public function test_deleting_a_pending_employee_without_history_soft_deletes_and_audits(): void
    {
        [$hr] = $this->signIn('hr');
        $pending = $this->person('Never Nick', ['status' => 'PENDING']);

        $this->delete("/admin/employees/{$pending->id}")->assertRedirect('/admin/employees')->assertSessionHas('success');

        $this->assertSoftDeleted('employees', ['id' => $pending->id]);
        $this->assertTrue(Activity::query()->where('action', 'employee.deleted')->where('actor_id', $hr->id)->exists());
        $this->get("/admin/employees/{$pending->id}")->assertNotFound();
    }

    public function test_delete_is_refused_with_a_friendly_message_when_history_exists_or_status_is_not_pending(): void
    {
        $this->signIn('hr');
        $active = $this->person('Active Amy');
        $withHistory = $this->person('History Hal', ['status' => 'PENDING']);
        $this->attendance($withHistory, '2026-09-29');

        $this->from("/admin/employees/{$active->id}")->delete("/admin/employees/{$active->id}")
            ->assertRedirect("/admin/employees/{$active->id}")
            ->assertSessionHas('error', 'Only pending employees with no history can be deleted. Terminate the employee instead.');
        $this->delete("/admin/employees/{$withHistory->id}")->assertSessionHas('error');

        $this->assertNotNull(Employee::query()->find($active->id));
        $this->assertNotNull(Employee::query()->find($withHistory->id));
    }

    public function test_actions_menu_offers_only_what_the_status_allows(): void
    {
        $this->signIn('hr');
        $pending = $this->person('Pending Pete', ['status' => 'PENDING']);
        $active = $this->person('Active Amy');

        $this->get("/admin/employees/{$pending->id}")->assertSee('Delete')->assertSee('Activate')->assertDontSee('Deactivate');
        $this->get("/admin/employees/{$active->id}")->assertSee('Deactivate')->assertSee('Terminate employment')->assertDontSee('Delete employee');
    }
}
