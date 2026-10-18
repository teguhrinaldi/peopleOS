<?php

namespace Tests\Feature\Admin;

use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Documents\DocumentTypeService;
use Illuminate\Support\Facades\Storage;
use Tests\Support\DocumentFixtures;
use Tests\Support\PayrollFixtures;

/** Shared setup for the Payroll / Salary / Payslips / Documents / Reports admin tests: demo components, HR and Admin users, fake private disk. */
abstract class Batch2TestCase extends AdminTestCase
{
    use DocumentFixtures, PayrollFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpPayroll();
        app(DocumentTypeService::class)->installDefaults();
    }

    /** A signed-in-able user whose only role is a custom one holding exactly these permissions. */
    protected function customUser(string $slug, array $permissions): User
    {
        [$user] = $this->makeStaff('employee');
        $role = Role::query()->create(['slug' => $slug, 'name' => ucfirst($slug)]);
        $role->permissions()->attach(Permission::query()->whereIn('slug', $permissions)->pluck('id')->all());
        $user->assignRole($slug);

        return $user;
    }

    /** An active employee with a salary, present on the given days (one July payroll later includes them). */
    protected function paidPerson(string $name = 'Paula Payroll', string $basic = '8000000'): Employee
    {
        $employee = $this->person($name);
        $this->giveSalary($employee, $basic);
        $this->present($employee, ['2026-07-01', '2026-07-02', '2026-07-03']);

        return $employee;
    }

    protected function finalizedPeriod(string $name = 'Paula Payroll'): PayrollPeriod
    {
        $this->paidPerson($name);

        return $this->toFinalized($this->period());
    }
}
