<?php

namespace Tests\Feature\Admin;

use App\Models\Activity;
use App\Models\EmployeeSalaryComponent;
use App\Models\SalaryComponent;
use App\Models\SalaryStructure;

class SalaryWebTest extends Batch2TestCase
{
    private function mealData(array $over = []): array
    {
        return $over + ['code' => 'MEAL', 'name' => 'Meal allowance', 'kind' => 'EARNING', 'calculation' => 'PER_PRESENT_DAY', 'default_amount' => '35000', 'sort_order' => '20', 'is_active' => '1'];
    }

    public function test_permissions(): void
    {
        $this->signIn('employee');
        $this->get(route('admin.salary-structures'))->assertForbidden();
        [$manager] = $this->signIn('manager');
        $this->get(route('admin.salary-structures'))->assertForbidden();
        $this->get(route('admin.salary-structures.components'))->assertForbidden();
        $this->post(route('admin.salary-structures.components.store'), $this->mealData())->assertForbidden();
        $this->post(route('admin.salary-structures.store'), ['code' => 'X', 'name' => 'X'])->assertForbidden();
        $this->assertSame(0, SalaryComponent::query()->where('code', 'MEAL')->count());
        $this->assertNotNull($manager);

        $this->actingAs($this->hr)->get(route('admin.salary-structures'))->assertOk()->assertSee('No tax or statutory rules are built in');
        $this->actingAs($this->hr)->get(route('admin.salary-structures.components'))->assertOk()->assertSee('Basic Salary')->assertSee('No tax or statutory rules');
    }

    public function test_component_catalogue_crud_and_rules(): void
    {
        $this->actingAs($this->hr);
        $this->post(route('admin.salary-structures.components.store'), $this->mealData())->assertRedirect(route('admin.salary-structures.components'))->assertSessionHas('success');
        $meal = SalaryComponent::query()->where('code', 'MEAL')->firstOrFail();
        $this->assertSame('35000.00', (string) $meal->default_amount);
        $this->assertTrue(Activity::query()->where('action', 'salary_component.created')->exists());

        $this->post(route('admin.salary-structures.components.store'), $this->mealData())->assertSessionHasErrors('code');
        $this->post(route('admin.salary-structures.components.store'), $this->mealData(['code' => 'B2', 'name' => 'Second basic', 'calculation' => 'FIXED', 'is_basic' => '1']))->assertSessionHasErrors('is_basic');
        $this->post(route('admin.salary-structures.components.store'), $this->mealData(['code' => 'T1', 'name' => 'Bad', 'kind' => 'EARNING', 'calculation' => 'PERCENT_OF_GROSS', 'default_rate' => '2', 'default_amount' => null]))->assertSessionHasErrors('calculation');
        $this->post(route('admin.salary-structures.components.store'), $this->mealData(['code' => 'bad code!']))->assertSessionHasErrors('code');

        $this->put(route('admin.salary-structures.components.update', $meal), $this->mealData(['name' => 'Lunch allowance']))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('Lunch allowance', $meal->fresh()->name);

        // Used by an employee => kind/calculation are locked; delete refused.
        $employee = $this->person('Sal Ary');
        $this->giveSalary($employee, '5000000', extra: []);
        $basic = $this->components['BASIC'];
        $this->put(route('admin.salary-structures.components.update', $basic), $this->mealData(['code' => 'BASIC', 'name' => 'Basic Salary', 'calculation' => 'PER_PRESENT_DAY', 'is_basic' => '0']))->assertSessionHasErrors('calculation');
        $this->delete(route('admin.salary-structures.components.destroy', $basic))->assertRedirect()->assertSessionHas('error');
        $this->assertNotNull($basic->fresh());

        $this->delete(route('admin.salary-structures.components.destroy', $meal))->assertRedirect()->assertSessionHas('success');
        $this->assertNull(SalaryComponent::query()->find($meal->id));
    }

    public function test_structures_create_edit_delete_and_assign(): void
    {
        $a = $this->person('Anna Assign');
        $b = $this->person('Bob Assign');
        $this->actingAs($this->hr);

        $lines = [
            ['salary_component_id' => $this->components['BASIC']->id, 'amount' => '6000000'],
            ['salary_component_id' => $this->components['TRANSPORT']->id, 'amount' => '40000'],
            ['salary_component_id' => $this->components['COOP']->id, 'amount' => '100000'],
        ];
        $this->post(route('admin.salary-structures.store'), ['code' => 'staff', 'name' => 'Staff', 'is_active' => '1', 'components' => $lines])->assertSessionHasNoErrors()->assertRedirect();
        $structure = SalaryStructure::query()->where('code', 'STAFF')->firstOrFail();
        $this->assertSame(3, $structure->lines()->count());
        $this->post(route('admin.salary-structures.store'), ['code' => 'STAFF', 'name' => 'Dup'])->assertSessionHasErrors('code');
        $this->post(route('admin.salary-structures.store'), ['code' => 'ZZ', 'name' => 'Bad', 'components' => [['salary_component_id' => 999999]]])->assertSessionHasErrors('components.0.salary_component_id');

        $this->get(route('admin.salary-structures.show', $structure))->assertOk()->assertSee('Basic Salary')->assertSee('6,000,000')->assertSee('Assign to employees');
        $this->get(route('admin.salary-structures.edit', $structure))->assertOk()->assertSee('Edit Staff');

        $this->post(route('admin.salary-structures.assign', $structure), ['employee_ids' => [], 'effective_from' => '2026-09-01'])->assertSessionHasErrors('employee_ids');
        $this->post(route('admin.salary-structures.assign', $structure), ['employee_ids' => [$a->id, $b->id], 'effective_from' => '2026-09-01', 'reason' => 'Onboarding'])
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $rows = EmployeeSalaryComponent::query()->where('employee_id', $a->id)->get();
        $this->assertCount(3, $rows);
        $this->assertSame($structure->id, $rows->first()->salary_structure_id);
        $this->assertSame('Onboarding', $rows->first()->reason);

        // Assign again from an earlier date: conflicts are reported per employee, nothing is overwritten.
        $this->post(route('admin.salary-structures.assign', $structure), ['employee_ids' => [$a->id], 'effective_from' => '2026-08-01'])->assertRedirect()->assertSessionHas('warning');
        $this->assertCount(3, EmployeeSalaryComponent::query()->where('employee_id', $a->id)->get());

        // Update replaces the template only; employees keep their values. Delete keeps history.
        $this->put(route('admin.salary-structures.update', $structure), ['code' => 'STAFF', 'name' => 'Staff v2', 'is_active' => '1', 'components' => [$lines[0]]])->assertSessionHasNoErrors();
        $this->assertSame(1, $structure->lines()->count());
        $this->assertCount(3, EmployeeSalaryComponent::query()->where('employee_id', $a->id)->get());
        $this->delete(route('admin.salary-structures.destroy', $structure))->assertRedirect(route('admin.salary-structures'));
        $this->assertCount(3, EmployeeSalaryComponent::query()->where('employee_id', $a->id)->get());
    }

    public function test_employee_salary_history_is_append_only(): void
    {
        $employee = $this->person('Hist Ory');
        $this->giveSalary($employee, '5000000', from: '2026-01-01', extra: ['COOP' => '100000']);
        $basic = $this->components['BASIC'];
        $this->actingAs($this->hr);

        $this->get(route('admin.employees.show', [$employee, 'tab' => 'payroll']))->assertOk()
            ->assertSee('Current salary')->assertSee('Salary history')->assertSee('Change salary from date')->assertSee('5,000,000')->assertSee('No tax or statutory rules');

        $this->post(route('admin.employees.salary.store', $employee), ['effective_from' => '2026-09-01', 'reason' => 'Annual raise', 'values' => [$basic->id => '6000000']])
            ->assertSessionHasNoErrors()->assertRedirect()->assertSessionHas('success');
        $rows = EmployeeSalaryComponent::query()->where('employee_id', $employee->id)->where('salary_component_id', $basic->id)->orderBy('effective_from')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('5000000.00', (string) $rows[0]->amount);
        $this->assertSame('2026-08-31', $rows[1]->effective_from->copy()->subDay()->toDateString());
        $this->assertSame('2026-08-31', $rows[0]->effective_to->toDateString());
        $this->assertNull($rows[1]->effective_to);
        $this->assertTrue(Activity::query()->where('action', 'employee_salary.changed')->exists());

        // No-op, overlapping date and invalid number are all friendly field errors; nothing changes.
        $this->post(route('admin.employees.salary.store', $employee), ['effective_from' => '2026-10-01', 'values' => []])->assertSessionHasErrors('effective_from');
        $this->post(route('admin.employees.salary.store', $employee), ['effective_from' => '2026-08-15', 'values' => [$basic->id => '7000000']])->assertSessionHasErrors('effective_from');
        $this->post(route('admin.employees.salary.store', $employee), ['effective_from' => '2026-10-01', 'values' => [$basic->id => 'abc']])->assertSessionHasErrors('values.'.$basic->id);
        $this->assertSame(2, EmployeeSalaryComponent::query()->where('employee_id', $employee->id)->where('salary_component_id', $basic->id)->count());

        // Removing a component ends it; the row stays.
        $coop = $this->components['COOP'];
        $this->post(route('admin.employees.salary.store', $employee), ['effective_from' => '2026-10-01', 'remove' => [$coop->id]])->assertSessionHasNoErrors();
        $this->assertSame('2026-09-30', EmployeeSalaryComponent::query()->where('employee_id', $employee->id)->where('salary_component_id', $coop->id)->first()->effective_to->toDateString());

        // There is no UI route that edits or deletes a history row.
        $this->put('/admin/employees/'.$employee->id.'/salary')->assertStatus(405);
        $this->delete('/admin/employees/'.$employee->id.'/salary')->assertStatus(405);
    }

    public function test_change_salary_is_hidden_and_refused_without_manage(): void
    {
        $employee = $this->person('No Manage');
        $this->giveSalary($employee, '5000000', extra: []);
        $viewerUser = $this->customUser('salary-viewer', ['admin.access', 'employee.view', 'salary.view']);

        $this->actingAs($viewerUser);
        $this->get(route('admin.employees.show', [$employee, 'tab' => 'payroll']))->assertOk()->assertSee('Current salary')->assertDontSee('Change salary from date');
        $this->post(route('admin.employees.salary.store', $employee), ['effective_from' => '2026-09-01', 'values' => [$this->components['BASIC']->id => '9']])->assertForbidden();
        $this->get(route('admin.salary-structures'))->assertOk()->assertDontSee('New structure');
        $this->post(route('admin.salary-structures.store'), ['code' => 'X', 'name' => 'X'])->assertForbidden();
    }

    public function test_manager_does_not_get_a_payroll_tab(): void
    {
        [$manager, $me] = $this->signIn('manager');
        $report = $this->person('Direct Report', assignment: ['manager_id' => $me->id]);
        $this->get(route('admin.employees.show', [$report, 'tab' => 'payroll']))->assertOk()->assertDontSee('Current salary')->assertDontSee('Salary history');
        $this->assertNotNull($manager);
    }

    public function test_pages_escape_component_names(): void
    {
        $xss = '<script>alert(1)</script>';
        $this->actingAs($this->hr);
        $this->post(route('admin.salary-structures.components.store'), $this->mealData(['code' => 'XSS', 'name' => $xss]))->assertSessionHasNoErrors();
        $employee = $this->person('Esc Ape');
        $this->giveSalary($employee, '5000000', extra: []);
        EmployeeSalaryComponent::query()->create(['employee_id' => $employee->id, 'salary_component_id' => SalaryComponent::query()->where('code', 'XSS')->value('id'), 'amount' => '10', 'effective_from' => '2026-01-01', 'reason' => $xss]);
        $this->post(route('admin.salary-structures.store'), ['code' => 'XS', 'name' => $xss, 'description' => $xss, 'components' => [['salary_component_id' => SalaryComponent::query()->where('code', 'XSS')->value('id'), 'amount' => '5']]]);
        $structure = SalaryStructure::query()->firstOrFail();

        foreach ([route('admin.salary-structures'), route('admin.salary-structures.components'), route('admin.salary-structures.show', $structure), route('admin.salary-structures.edit', $structure), route('admin.employees.show', [$employee, 'tab' => 'payroll'])] as $url) {
            $this->assertStringNotContainsString($xss, $this->get($url)->assertOk()->getContent(), $url);
        }
    }
}
