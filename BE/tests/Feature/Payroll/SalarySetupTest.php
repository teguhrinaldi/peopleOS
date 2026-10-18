<?php

namespace Tests\Feature\Payroll;

use App\Models\Activity;
use App\Models\EmployeeSalaryComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PayrollFixtures;
use Tests\Support\WorkplaceFixtures;
use Tests\TestCase;

class SalarySetupTest extends TestCase
{
    use PayrollFixtures, RefreshDatabase, WorkplaceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpWorkplace();
        $this->setUpPayroll();
        $this->at('2026-09-30 10:00');
    }

    public function test_component_crud_with_rules(): void
    {
        $this->actingAsApi($this->hr);
        $id = $this->postJson('/api/v1/salary-components', [
            'code' => 'meal', 'name' => 'Meal Allowance', 'kind' => 'EARNING', 'calculation' => 'PER_PRESENT_DAY', 'default_amount' => 35000,
        ])->assertCreated()->assertJsonPath('data.code', 'MEAL')->assertJsonPath('data.default_amount', '35000.00')->json('data.id');

        $this->postJson('/api/v1/salary-components', ['code' => 'MEAL', 'name' => 'Dup', 'kind' => 'EARNING', 'calculation' => 'FIXED'])->assertStatus(422);
        $this->postJson('/api/v1/salary-components', ['code' => 'PG', 'name' => 'Bad', 'kind' => 'EARNING', 'calculation' => 'PERCENT_OF_GROSS'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['calculation']]]);
        $this->postJson('/api/v1/salary-components', ['code' => 'B2', 'name' => 'Second basic', 'kind' => 'EARNING', 'calculation' => 'FIXED', 'is_basic' => true])
            ->assertStatus(409)->assertJsonPath('error.code', 'BASIC_COMPONENT_EXISTS');
        $this->postJson('/api/v1/salary-components', ['code' => 'X', 'name' => 'X', 'kind' => 'BONUS', 'calculation' => 'TAX'])->assertStatus(422);

        $this->patchJson("/api/v1/salary-components/{$id}", ['name' => 'Meal'])->assertOk()->assertJsonPath('data.name', 'Meal');
        $this->getJson('/api/v1/salary-components?kind=EARNING')->assertOk()->assertJsonCount(5, 'data');
        $this->deleteJson("/api/v1/salary-components/{$id}")->assertOk();

        // a component holding employee values cannot change meaning or be deleted
        [, $e] = $this->makeStaff();
        $this->giveSalary($e, '5000000');
        $basic = $this->components['BASIC']->id;
        $this->patchJson("/api/v1/salary-components/{$basic}", ['calculation' => 'PER_PRESENT_DAY', 'is_basic' => false])
            ->assertStatus(409)->assertJsonPath('error.code', 'COMPONENT_IN_USE');
        $this->deleteJson("/api/v1/salary-components/{$basic}")->assertStatus(409)->assertJsonPath('error.code', 'COMPONENT_IN_USE');
        $this->patchJson("/api/v1/salary-components/{$basic}", ['is_active' => false])->assertOk();

        foreach (['salary_component.created', 'salary_component.updated', 'salary_component.deleted'] as $action) {
            $this->assertTrue(Activity::query()->where('action', $action)->exists(), $action);
        }
        $updated = Activity::query()->where('action', 'salary_component.updated')->first();
        $this->assertSame(['name' => 'Meal'], $updated->new_values, 'only what changed is logged');
    }

    public function test_structures_crud_and_assignment_copies_values_effective_dated(): void
    {
        [, $e] = $this->makeStaff();
        $this->actingAsApi($this->hr);
        $c = fn (string $code) => $this->components[$code]->id;

        $sid = $this->postJson('/api/v1/salary-structures', [
            'code' => 'staff', 'name' => 'Staff', 'components' => [
                ['salary_component_id' => $c('BASIC'), 'amount' => 6000000],
                ['salary_component_id' => $c('TRANSPORT'), 'amount' => 50000],
                ['salary_component_id' => $c('UNPAID')],
                ['salary_component_id' => $c('COOP')],
            ],
        ])->assertCreated()->assertJsonPath('data.code', 'STAFF')->assertJsonCount(4, 'data.components')
            ->assertJsonPath('data.components.3.amount', '100000.00')->json('data.id');

        $this->postJson("/api/v1/salary-structures/{$sid}/assign", [
            'employee_id' => $e->id, 'effective_from' => '2026-01-01',
            'overrides' => [['salary_component_id' => $c('BASIC'), 'amount' => 8000000]],
        ])->assertCreated()->assertJsonCount(4, 'data.created');

        $this->getJson("/api/v1/employees/{$e->id}/salary?date=2026-06-15")->assertOk()
            ->assertJsonPath('data.current.0.code', 'BASIC')->assertJsonPath('data.current.0.amount', '8000000.00')
            ->assertJsonCount(4, 'data.current');

        // template edits never touch employees
        $this->patchJson("/api/v1/salary-structures/{$sid}", ['components' => [['salary_component_id' => $c('BASIC'), 'amount' => 6500000]]])
            ->assertOk()->assertJsonCount(1, 'data.components');
        $this->assertSame(4, EmployeeSalaryComponent::query()->where('employee_id', $e->id)->count());

        // re-assigning the (now basic-only) structure from July ends the components it no longer lists
        $this->postJson("/api/v1/salary-structures/{$sid}/assign", ['employee_id' => $e->id, 'effective_from' => '2026-07-01'])
            ->assertCreated()->assertJsonPath('data.ended', 3);
        $this->getJson("/api/v1/employees/{$e->id}/salary?date=2026-07-15")->assertOk()->assertJsonCount(1, 'data.current')
            ->assertJsonPath('data.current.0.amount', '6500000.00')->assertJsonCount(5, 'data.history');

        $this->deleteJson("/api/v1/salary-structures/{$sid}")->assertOk();
        $this->assertSame(5, EmployeeSalaryComponent::query()->where('employee_id', $e->id)->count(), 'history survives template deletion');
        $this->assertTrue(Activity::query()->where('action', 'salary_structure.created')->exists());
        $this->assertTrue(Activity::query()->where('action', 'salary_structure.updated')->exists());
    }

    public function test_employee_salary_change_is_effective_dated_and_audited(): void
    {
        [, $e] = $this->makeStaff();
        $this->giveSalary($e, '8000000', '2026-01-01', []);
        $basic = $this->components['BASIC']->id;
        $this->actingAsApi($this->hr);

        $this->postJson("/api/v1/employees/{$e->id}/salary", [
            'effective_from' => '2026-07-01', 'reason' => 'Annual raise',
            'components' => [['salary_component_id' => $basic, 'amount' => 9000000]],
        ])->assertCreated()->assertJsonPath('data.created.0.amount', '9000000.00')->assertJsonPath('data.warnings', []);

        $this->getJson("/api/v1/employees/{$e->id}/salary?date=2026-06-30")->assertJsonPath('data.current.0.amount', '8000000.00');
        $this->getJson("/api/v1/employees/{$e->id}/salary?date=2026-07-01")->assertJsonPath('data.current.0.amount', '9000000.00');

        // the timeline only grows forward; identical values are a no-op
        $this->postJson("/api/v1/employees/{$e->id}/salary", [
            'effective_from' => '2026-07-01', 'reason' => 'Overwrite?', 'components' => [['salary_component_id' => $basic, 'amount' => 1]],
        ])->assertStatus(409)->assertJsonPath('error.code', 'SALARY_CHANGE_CONFLICT');
        $this->postJson("/api/v1/employees/{$e->id}/salary", [
            'effective_from' => '2026-08-01', 'reason' => 'Same value', 'components' => [['salary_component_id' => $basic, 'amount' => 9000000]],
        ])->assertStatus(422)->assertJsonPath('error.code', 'NO_CHANGES');
        $this->postJson("/api/v1/employees/{$e->id}/salary", [
            'effective_from' => '2026-08-01', 'reason' => 'Negative', 'components' => [['salary_component_id' => $basic, 'amount' => -5]],
        ])->assertStatus(422);

        $log = Activity::query()->where('action', 'employee_salary.changed')->latest('id')->first();
        $this->assertSame('2026-07-01', $log->new_values['effective_from']);
        $this->assertEquals([['component' => 'BASIC', 'from' => ['amount' => '8000000.00'], 'to' => ['amount' => '9000000.00']]], $log->new_values['changes']);
    }

    public function test_salary_endpoints_require_salary_permissions(): void
    {
        [$employee, $self] = $this->makeStaff();
        [$manager] = $this->makeStaff('manager');

        foreach ([$employee, $manager] as $user) {
            $this->actingAsApi($user)->getJson("/api/v1/employees/{$self->id}/salary")->assertForbidden();
            $this->getJson('/api/v1/salary-structures')->assertForbidden();
            $this->postJson("/api/v1/employees/{$self->id}/salary", [
                'effective_from' => '2026-07-01', 'reason' => 'Self raise', 'components' => [['salary_component_id' => $this->components['BASIC']->id, 'amount' => 99000000]],
            ])->assertForbidden();
        }
        $this->actingAsApi($this->hr)->getJson('/api/v1/employees/999999/salary')->assertNotFound();
        $this->assertSame(0, EmployeeSalaryComponent::query()->count());
    }
}
