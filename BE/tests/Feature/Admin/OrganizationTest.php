<?php

namespace Tests\Feature\Admin;

use App\Models\Activity;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Position;

class OrganizationTest extends AdminTestCase
{
    public function test_department_create_update_and_duplicate_code(): void
    {
        [$hr] = $this->signIn('hr');

        $this->post('/admin/departments', ['code' => 'fin', 'name' => 'Finance', 'branch_id' => $this->branch->id, 'is_active' => 1])
            ->assertRedirect('/admin/departments')->assertSessionHas('success');

        $finance = Department::query()->where('code', 'FIN')->firstOrFail();
        $this->assertTrue($finance->is_active);
        $this->assertTrue(Activity::query()->where('action', 'department.created')->where('actor_id', $hr->id)->exists());

        $this->put("/admin/departments/{$finance->id}", ['code' => 'FIN', 'name' => 'Finance & Tax', 'is_active' => 0])->assertSessionHasNoErrors();
        $this->assertSame('Finance & Tax', $finance->fresh()->name);
        $this->assertFalse($finance->fresh()->is_active);
        $this->assertTrue(Activity::query()->where('action', 'department.updated')->exists());

        $this->post('/admin/departments', ['code' => 'MKT', 'name' => 'Dup'])->assertSessionHasErrors(['code' => 'This code is already used by another department.']);
        $this->post('/admin/departments', ['code' => 'bad code!', 'name' => 'X'])->assertSessionHasErrors('code');
        $this->post('/admin/departments', ['code' => 'Q', 'name' => 'Q', 'branch_id' => 9999, 'parent_id' => 9999])->assertSessionHasErrors(['branch_id', 'parent_id']);
    }

    public function test_a_department_with_people_cannot_be_deleted_but_can_be_deactivated(): void
    {
        $this->signIn('hr');
        $this->person('Placed Pat');

        $this->from('/admin/departments')->delete("/admin/departments/{$this->department->id}")
            ->assertRedirect('/admin/departments')
            ->assertSessionHas('error');
        $this->assertStringContainsString('still has 2 employees', session('error'));
        $this->assertNotSoftDeleted('departments', ['id' => $this->department->id]);

        $this->put("/admin/departments/{$this->department->id}", ['code' => 'MKT', 'name' => 'Marketing', 'is_active' => 0])->assertSessionHasNoErrors();
        $this->assertFalse($this->department->fresh()->is_active);
    }

    public function test_an_empty_department_can_be_deleted_and_is_audited(): void
    {
        $this->signIn('hr');
        $empty = Department::query()->create(['company_id' => $this->company->id, 'code' => 'EMP', 'name' => 'Empty Dept']);

        $this->delete("/admin/departments/{$empty->id}")->assertSessionHas('success');

        $this->assertSoftDeleted('departments', ['id' => $empty->id]);
        $this->assertTrue(Activity::query()->where('action', 'department.deleted')->where('entity_id', $empty->id)->exists());
    }

    public function test_only_people_who_currently_hold_the_department_block_deletion(): void
    {
        $this->signIn('hr');
        $sales = Department::query()->create(['company_id' => $this->company->id, 'code' => 'SAL', 'name' => 'Sales']);
        $this->person('Old Owen', [], ['department_id' => $sales->id, 'effective_from' => '2025-01-01', 'effective_to' => '2026-01-31']);
        $terminated = $this->person('Gone Gary', ['status' => 'TERMINATED', 'terminated_at' => '2026-03-01'], ['department_id' => $sales->id]);
        $this->assertNotNull($terminated);

        $this->delete("/admin/departments/{$sales->id}")->assertSessionHas('success');
    }

    public function test_sub_departments_block_deleting_the_parent_and_cycles_are_rejected(): void
    {
        $this->signIn('hr');
        $child = Department::query()->create(['company_id' => $this->company->id, 'parent_id' => $this->department->id, 'code' => 'SUB', 'name' => 'Sub Team']);

        $this->delete("/admin/departments/{$this->department->id}")->assertSessionHas('error');
        $this->assertNotSoftDeleted('departments', ['id' => $this->department->id]);

        $this->put("/admin/departments/{$this->department->id}", ['code' => 'MKT', 'name' => 'Marketing', 'parent_id' => $child->id, 'is_active' => 1])
            ->assertSessionHasErrors('parent_id');
        $this->put("/admin/departments/{$this->department->id}", ['code' => 'MKT', 'name' => 'Marketing', 'parent_id' => $this->department->id, 'is_active' => 1])
            ->assertSessionHasErrors('parent_id');
    }

    public function test_position_crud_and_deletion_protection(): void
    {
        $this->signIn('hr');

        $this->post('/admin/positions', ['code' => 'ana', 'name' => 'Analyst', 'level' => 3, 'is_active' => 1])->assertSessionHas('success');
        $analyst = Position::query()->where('code', 'ANA')->firstOrFail();
        $this->post('/admin/positions', ['code' => 'STF', 'name' => 'Dup'])->assertSessionHasErrors('code');
        $this->post('/admin/positions', ['code' => 'LV', 'name' => 'Lvl', 'level' => 99])->assertSessionHasErrors('level');

        $this->put("/admin/positions/{$analyst->id}", ['code' => 'ANA', 'name' => 'Senior Analyst', 'level' => 4, 'is_active' => 1])->assertSessionHasNoErrors();
        $this->assertSame('Senior Analyst', $analyst->fresh()->name);

        $this->person('Holder Hal');
        $this->delete("/admin/positions/{$this->position->id}")->assertSessionHas('error');
        $this->assertNotSoftDeleted('positions', ['id' => $this->position->id]);

        $this->delete("/admin/positions/{$analyst->id}")->assertSessionHas('success');
        $this->assertSoftDeleted('positions', ['id' => $analyst->id]);
    }

    public function test_branch_crud_and_protection(): void
    {
        $this->signIn('hr');

        $this->post('/admin/organization/branches', ['code' => 'jkt', 'name' => 'Jakarta', 'city' => 'Jakarta', 'is_active' => 1])->assertSessionHas('success');
        $jakarta = Branch::query()->where('code', 'JKT')->firstOrFail();
        $this->post('/admin/organization/branches', ['code' => 'HO', 'name' => 'Dup'])->assertSessionHasErrors('code');

        $this->put("/admin/organization/branches/{$jakarta->id}", ['code' => 'JKT', 'name' => 'Jakarta Office', 'is_active' => 1])->assertSessionHasNoErrors();
        $this->assertSame('Jakarta Office', $jakarta->fresh()->name);

        $this->delete("/admin/organization/branches/{$this->branch->id}")->assertSessionHas('error'); // departments + a work location hang off it
        $this->assertNotSoftDeleted('branches', ['id' => $this->branch->id]);

        $this->delete("/admin/organization/branches/{$jakarta->id}")->assertSessionHas('success');
        $this->assertSoftDeleted('branches', ['id' => $jakarta->id]);
    }

    public function test_company_profile_update_validates_the_timezone(): void
    {
        [$hr] = $this->signIn('hr');

        $this->put('/admin/organization/company', ['name' => 'Renamed Co', 'timezone' => 'Mars/Olympus'])->assertSessionHasErrors('timezone');
        $this->put('/admin/organization/company', ['name' => 'Renamed Co', 'timezone' => 'Asia/Makassar', 'email' => 'not-an-email'])->assertSessionHasErrors('email');

        $this->put('/admin/organization/company', ['name' => 'Renamed Co', 'legal_name' => 'PT Renamed', 'timezone' => 'Asia/Makassar', 'email' => 'hi@renamed.example'])
            ->assertRedirect('/admin/organization')->assertSessionHas('success');

        $this->assertSame('Asia/Makassar', $this->company->fresh()->timezone);
        $log = Activity::query()->where('action', 'company.updated')->firstOrFail();
        $this->assertSame($hr->id, $log->actor_id);
        $this->assertSame('Asia/Makassar', $log->new_values['timezone']);
    }

    public function test_the_organization_page_shows_the_tree_with_headcounts(): void
    {
        $this->signIn('hr');
        $this->person('Placed Pat');
        $this->person('Placed Pam');
        Department::query()->create(['company_id' => $this->company->id, 'parent_id' => $this->department->id, 'code' => 'SUB', 'name' => 'Sub Team']);

        $page = $this->get('/admin/organization')->assertOk()->assertSee('Head Office')->assertSee('Marketing')->assertSee('Sub Team');
        $this->assertSame(2, $page->viewData('overview')['totals']['departments']);
        $this->assertSame(3, $page->viewData('overview')['branches']->firstWhere('code', 'HO')['headcount']); // 2 people + the signed-in HR employee
    }

    public function test_managers_can_view_but_not_change_the_organization(): void
    {
        $this->signIn('manager');

        $this->get('/admin/departments')->assertOk()->assertDontSee('Add department');
        $this->get('/admin/positions')->assertOk()->assertDontSee('Add position');
        $this->get('/admin/organization')->assertOk()->assertDontSee('Save company profile')->assertDontSee('Add branch');
        $this->delete("/admin/departments/{$this->department->id}")->assertForbidden();
        $this->put("/admin/departments/{$this->department->id}", ['code' => 'MKT', 'name' => 'Hacked'])->assertForbidden();
        $this->assertSame('Marketing', $this->department->fresh()->name);
    }

    public function test_modal_forms_reopen_with_errors_and_old_input(): void
    {
        $this->signIn('hr');

        $this->followingRedirects()->from('/admin/departments')
            ->post('/admin/departments', ['_form' => 'department', 'code' => 'MKT', 'name' => 'Dupe'])
            ->assertSee('This code is already used by another department.')
            ->assertSee('Dupe');
    }
}
