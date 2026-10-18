<?php

namespace Tests\Feature\Admin;

use App\Support\AdminNavigation;
use PHPUnit\Framework\Attributes\DataProvider;

class PermissionGatesTest extends AdminTestCase
{
    /** @return array<string, array{0: string, 1: list<string>, 2: list<string>}> role => [allowed paths], [forbidden paths] */
    public static function matrix(): array
    {
        return [
            'admin' => ['admin', [
                '/admin', '/admin/employees', '/admin/employees/create', '/admin/departments', '/admin/positions', '/admin/organization',
                '/admin/attendance', '/admin/shifts', '/admin/work-locations', '/admin/overtime', '/admin/leave-requests', '/admin/leave-types',
                '/admin/payroll', '/admin/salary-structures', '/admin/payslips', '/admin/documents', '/admin/reports', '/admin/activity', '/admin/settings',
            ], []],
            'hr' => ['hr', [
                '/admin', '/admin/employees', '/admin/employees/create', '/admin/departments', '/admin/positions', '/admin/organization',
                '/admin/attendance', '/admin/shifts', '/admin/payroll', '/admin/documents', '/admin/reports', '/admin/activity',
            ], ['/admin/settings']],
            'manager' => ['manager', [
                '/admin', '/admin/employees', '/admin/departments', '/admin/positions', '/admin/organization',
                '/admin/attendance', '/admin/overtime', '/admin/leave-requests', '/admin/reports',
            ], [
                '/admin/employees/create', '/admin/shifts', '/admin/work-locations', '/admin/leave-types', '/admin/payroll',
                '/admin/salary-structures', '/admin/payslips', '/admin/documents', '/admin/activity', '/admin/settings',
            ]],
            'employee' => ['employee', [], [
                '/admin', '/admin/employees', '/admin/departments', '/admin/organization', '/admin/attendance', '/admin/reports', '/admin/settings',
            ]],
        ];
    }

    /**
     * @param  list<string>  $allowed
     * @param  list<string>  $forbidden
     */
    #[DataProvider('matrix')]
    public function test_each_role_reaches_exactly_the_pages_its_permissions_allow(string $role, array $allowed, array $forbidden): void
    {
        $this->signIn($role);

        foreach ($allowed as $path) {
            $this->get($path)->assertOk();
        }
        foreach ($forbidden as $path) {
            $this->get($path)->assertForbidden();
        }
    }

    public function test_mutating_routes_are_guarded_by_permission_not_just_hidden(): void
    {
        [, $report] = $this->makeStaff('employee');
        [$manager] = $this->makeStaff('manager');
        $this->actingAs($manager);

        $this->post('/admin/employees', ['full_name' => 'X'])->assertForbidden();
        $this->post('/admin/departments', ['code' => 'ZZ', 'name' => 'Z'])->assertForbidden();
        $this->post('/admin/positions', ['code' => 'ZZ', 'name' => 'Z'])->assertForbidden();
        $this->post('/admin/organization/branches', ['code' => 'ZZ', 'name' => 'Z'])->assertForbidden();
        $this->put('/admin/organization/company', ['name' => 'Hijacked', 'timezone' => 'UTC'])->assertForbidden();
        $this->post("/admin/employees/{$report->id}/terminate", ['terminated_at' => '2026-09-30', 'reason' => 'x', 'confirm' => 1])->assertForbidden();
        $this->delete("/admin/employees/{$report->id}")->assertForbidden();

        $this->assertSame('Test Co', $this->company->fresh()->name);
    }

    public function test_sidebar_shows_only_what_the_user_may_open(): void
    {
        $this->signIn('manager');
        $html = $this->get('/admin')->assertOk()->getContent();

        foreach (['Employees', 'Departments', 'Organization', 'Attendance', 'Overtime', 'Leave Requests', 'Reports'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        foreach (['Salary Structures', 'Payslips', 'Work Locations', 'Shifts', 'Settings', 'Leave Types'] as $label) {
            $this->assertStringNotContainsString($label, $html);
        }
    }

    public function test_admin_sidebar_follows_the_locked_grouped_information_architecture(): void
    {
        $this->signIn('admin');
        $html = $this->get('/admin')->assertOk()->getContent();

        $order = ['Dashboard', 'People', 'Employees', 'Departments', 'Positions', 'Organization', 'Attendance', 'Shifts', 'Work Locations', 'Overtime',
            'Leave', 'Leave Requests', 'Leave Types', 'Payroll', 'Salary Structures', 'Payslips', 'Documents', 'Reports', 'Settings'];

        $nav = substr($html, (int) strpos($html, 'aria-label="Main navigation"'));
        $nav = substr($nav, 0, (int) strpos($nav, '</nav>'));
        $last = 0;
        foreach ($order as $label) {
            $pos = strpos($nav, '>'.$label.'<', $last) ?: strpos($nav, $label, $last);
            $this->assertNotFalse($pos, "Sidebar is missing or misordered: {$label}");
            $last = $pos;
        }

        // Locked IA: the audit log lives inside Settings, never as its own top-level item.
        $this->assertStringNotContainsString('>Activity<', $nav);
        $this->assertStringNotContainsString(route('admin.activity'), $nav);
    }

    public function test_every_sidebar_link_resolves_to_a_working_page(): void
    {
        $this->signIn('admin');

        foreach (AdminNavigation::forUser(auth()->user()) as $entry) {
            foreach ($entry['type'] === 'link' ? [$entry] : $entry['items'] as $item) {
                $this->get(route($item['route']))->assertOk();
            }
        }
    }

    public function test_every_sidebar_module_is_built_and_no_placeholder_is_left(): void
    {
        $this->signIn('admin');

        foreach (['/admin/payroll', '/admin/salary-structures', '/admin/payslips', '/admin/documents', '/admin/reports'] as $path) {
            $this->get($path)->assertOk()->assertDontSee('This module arrives in a later release');
        }
    }

    public function test_error_pages_use_the_product_design(): void
    {
        $this->signIn('admin');

        $this->get('/admin/nope')->assertNotFound()->assertSee('We cannot find that page');
        $this->get('/admin/employees/999999')->assertNotFound();

        $this->signIn('manager');
        $this->get('/admin/settings')->assertForbidden()->assertSee('You do not have access to this page');
    }
}
