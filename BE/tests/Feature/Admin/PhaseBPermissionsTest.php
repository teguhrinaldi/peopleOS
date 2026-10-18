<?php

namespace Tests\Feature\Admin;

use PHPUnit\Framework\Attributes\DataProvider;

class PhaseBPermissionsTest extends AdminTestCase
{
    private const PAGES = [
        'board' => '/admin/attendance',
        'corrections' => '/admin/attendance/corrections',
        'overtime' => '/admin/overtime',
        'shifts' => '/admin/shifts',
        'shift_form' => '/admin/shifts/create',
        'overrides' => '/admin/shifts/assignments',
        'calendars' => '/admin/shifts/calendars',
        'holidays' => '/admin/shifts/holidays',
        'locations' => '/admin/work-locations',
        'location_form' => '/admin/work-locations/create',
        'policies' => '/admin/work-locations/policies',
        'activity' => '/admin/activity',
        'settings' => '/admin/settings',
        'roles' => '/admin/settings/roles',
    ];

    /** @return array<string, array{0: string, 1: list<string>}> role => pages it may open (all others must be 403) */
    public static function matrix(): array
    {
        return [
            'admin' => ['admin', array_keys(self::PAGES)],
            'hr' => ['hr', ['board', 'corrections', 'overtime', 'shifts', 'shift_form', 'overrides', 'calendars', 'holidays', 'locations', 'location_form', 'policies', 'activity']],
            'manager' => ['manager', ['board', 'corrections', 'overtime']],
            'employee' => ['employee', []],
        ];
    }

    /** @param list<string> $allowed */
    #[DataProvider('matrix')]
    public function test_each_role_opens_exactly_the_phase_b_pages_its_permissions_allow(string $role, array $allowed): void
    {
        $this->signIn($role);

        foreach (self::PAGES as $key => $path) {
            $expected = in_array($key, $allowed, true) ? 200 : 403;
            $this->assertSame($expected, $this->get($path)->status(), "{$role} on {$path}");
        }
    }

    public function test_a_manager_cannot_change_setup_data_even_by_posting_directly(): void
    {
        $this->signIn('manager');

        $this->post('/admin/shifts', ['code' => 'X', 'name' => 'X', 'start_time' => '08:00', 'end_time' => '16:00', 'grace_late_minutes' => 0, 'grace_early_leave_minutes' => 0])->assertForbidden();
        $this->put("/admin/shifts/{$this->shift->id}", ['code' => 'REG', 'name' => 'Hacked'])->assertForbidden();
        $this->post('/admin/work-locations', ['code' => 'X', 'name' => 'X', 'latitude' => 0, 'longitude' => 0, 'radius_meters' => 50])->assertForbidden();
        $this->post('/admin/work-locations/policies', ['name' => 'Loose', 'geofence_mode' => 'off'])->assertForbidden();
        $this->post('/admin/shifts/holidays', ['date' => '2026-12-31', 'name' => 'X', 'type' => 'company'])->assertForbidden();
        $this->put('/admin/settings', ['name' => 'Hijacked', 'timezone' => 'UTC'])->assertForbidden();
        $this->post("/admin/attendance/{$this->attendance($this->person('Someone'), '2026-09-29')->id}/review", ['decision' => 'approve'])->assertForbidden();

        $this->assertSame('REG shift', $this->shift->fresh()->name);
        $this->assertSame('Test Co', $this->company->fresh()->name);
    }

    public function test_hr_cannot_change_settings(): void
    {
        $this->signIn('hr');

        $this->put('/admin/settings', ['name' => 'Hijacked', 'timezone' => 'UTC'])->assertForbidden();
        $this->assertSame('Asia/Jakarta', $this->company->fresh()->timezone);
    }

    public function test_the_sidebar_links_are_real_modules_now(): void
    {
        $this->signIn('admin');

        foreach (['/admin/attendance', '/admin/shifts', '/admin/work-locations', '/admin/overtime', '/admin/activity', '/admin/settings'] as $path) {
            $this->get($path)->assertOk()->assertDontSee('This module arrives in a later release');
        }
        $this->get('/admin/reports')->assertOk()->assertDontSee('This module arrives in a later release');
    }

    /** @return string the first desktop sidebar block (the drawer repeats it) */
    private function sidebar(string $path): string
    {
        $html = $this->get($path)->assertOk()->getContent();
        $nav = substr($html, (int) strpos($html, 'aria-label="Main navigation"'));

        return substr($nav, 0, (int) strpos($nav, '</nav>'));
    }

    public function test_settings_holds_the_audit_log_and_opens_on_the_page_the_user_may_see(): void
    {
        $this->signIn('admin');
        $nav = $this->sidebar('/admin');
        $this->assertStringContainsString('href="'.route('admin.settings').'"', $nav);

        // On the audit log, the Settings item is the active one.
        $nav = $this->sidebar('/admin/activity');
        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('admin.settings'), '#').'"\s+aria-current="page"#', $nav);
        $this->get('/admin/activity')->assertSee('Audit log')->assertSee('Roles &amp; permissions', false)->assertSee('General');
        $this->get('/admin/settings')->assertSee('Audit log');
    }

    public function test_hr_reaches_only_the_audit_log_tab_of_settings(): void
    {
        $this->signIn('hr');

        $nav = $this->sidebar('/admin');
        $this->assertStringContainsString('>Settings<', $nav);
        $this->assertStringContainsString('href="'.route('admin.activity').'"', $nav, 'HR lands on the audit log');
        $this->assertStringNotContainsString('href="'.route('admin.settings').'"', $nav);

        $page = $this->get('/admin/activity')->assertOk()->assertSee('Audit log');
        $page->assertDontSee('href="'.route('admin.settings.roles').'"', false)->assertDontSee('href="'.route('admin.settings').'"', false);
        $this->get('/admin/settings')->assertForbidden();
        $this->get('/admin/settings/roles')->assertForbidden();
    }

    public function test_managers_see_no_settings_at_all(): void
    {
        $this->signIn('manager');

        $this->assertStringNotContainsString('>Settings<', $this->sidebar('/admin'));
        $this->get('/admin/activity')->assertForbidden();
    }

    public function test_the_audit_trail_has_no_write_routes(): void
    {
        $this->signIn('admin');

        $this->post('/admin/activity')->assertStatus(405);
        $this->delete('/admin/activity')->assertStatus(405);
    }
}
