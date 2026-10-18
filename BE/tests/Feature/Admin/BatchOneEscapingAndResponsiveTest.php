<?php

namespace Tests\Feature\Admin;

use App\Models\Holiday;
use App\Services\Leave\LeaveService;

class BatchOneEscapingAndResponsiveTest extends AdminTestCase
{
    private const SCRIPT = '<script>alert(1)</script>';

    public function test_leave_names_reasons_and_notes_are_escaped_on_every_new_page(): void
    {
        [$hr] = $this->signIn('admin');
        $type = $this->leaveType('XSS', self::SCRIPT);
        $employee = $this->person(self::SCRIPT);
        $request = $this->leaveFor($employee, $type, '2026-10-05', reason: self::SCRIPT);
        $this->leaveFor($this->person('Decided Dee'), $type, '2026-10-06', reason: 'ok');
        app(LeaveService::class)->reject($hr, $request, self::SCRIPT);
        Holiday::query()->create(['company_id' => $this->company->id, 'date' => '2026-10-07', 'name' => self::SCRIPT, 'type' => 'company']);

        foreach ([
            '/admin', '/admin/leave-requests', '/admin/leave-requests?tab=history', '/admin/leave-requests?tab=calendar&month=2026-10',
            "/admin/leave-requests/{$request->id}", "/admin/leave-requests/{$request->id}?panel=1", '/admin/leave-types',
            "/admin/leave-types/{$type->id}/edit", "/admin/employees/{$employee->id}?tab=leave", '/admin/settings',
        ] as $page) {
            $html = $this->get($page)->assertOk()->getContent();
            $this->assertStringNotContainsString(self::SCRIPT, $html, "Unescaped markup on {$page}");
        }
    }

    public function test_the_attendance_board_filter_values_and_search_text_are_escaped(): void
    {
        $this->signIn('hr');

        $html = $this->get('/admin/employees?q='.urlencode(self::SCRIPT))->assertOk()->getContent();
        $this->assertStringNotContainsString(self::SCRIPT, $html);
    }

    public function test_tables_carry_the_responsive_hooks(): void
    {
        $this->signIn('hr');
        $this->person('Row Ray');
        $this->leaveFor($this->person('Leave Lee'), $this->leaveType());

        foreach (['/admin/employees', '/admin/attendance', '/admin/leave-requests', '/admin/leave-types', '/admin/shifts', '/admin/departments'] as $page) {
            $html = $this->get($page)->assertOk()->getContent();
            $this->assertStringContainsString('dt-sticky', $html, "{$page}: sticky first column");
            $this->assertStringContainsString('overflow-x-auto', $html, "{$page}: horizontal scroll container");
        }

        $employees = $this->get('/admin/employees')->getContent();
        $this->assertStringContainsString('hidden lg:table-cell', $employees, 'low-priority columns hide on small screens');
        $this->assertStringContainsString('aria-label="Close filters"', $employees, 'filters collapse into a sheet on phones');
        $this->assertStringContainsString('aria-label="Close filters"', $this->get('/admin/leave-requests')->getContent());
        $this->assertStringContainsString('aria-label="Close filters"', $this->get('/admin/attendance')->getContent());
    }

    public function test_the_stylesheet_respects_reduced_motion_and_keeps_focus_outlines(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('prefers-reduced-motion: reduce', $css);
        $this->assertStringContainsString('transition-duration: 0.01ms !important', $css);
        $this->assertStringContainsString(':focus-visible', $css);
        $this->assertStringContainsString('.nav-link:focus-visible', $css);
    }

    public function test_status_is_never_colour_only_on_the_leave_pages(): void
    {
        $this->signIn('hr');
        $request = $this->leaveFor($this->person('Status Stu'), $this->leaveType());

        $this->get('/admin/leave-requests?tab=history')->assertSee('Pending');
        $this->get("/admin/leave-requests/{$request->id}?panel=1")->assertSee('Pending')->assertSee('Annual Leave');
        $this->get('/admin/leave-requests?tab=calendar&month=2026-10')->assertSee('pending', false);
    }
}
