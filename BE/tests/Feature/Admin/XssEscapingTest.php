<?php

namespace Tests\Feature\Admin;

use App\Models\Department;
use App\Services\ActivityLogger;

class XssEscapingTest extends AdminTestCase
{
    private const SCRIPT = '<script>alert(1)</script>';

    private const IMG = '"><img src=x onerror=alert(2)>';

    public function test_user_supplied_text_is_escaped_everywhere_it_is_rendered(): void
    {
        [$hr] = $this->signIn('hr');
        $evil = $this->person(self::SCRIPT, ['address' => self::SCRIPT, 'email' => 'evil@corp.example']);
        $department = Department::query()->create(['company_id' => $this->company->id, 'code' => 'EVL', 'name' => self::IMG]);
        app(ActivityLogger::class)->log('employee.updated', $evil, ['full_name' => 'x'], ['full_name' => self::SCRIPT], actor: $hr);

        $pages = [
            '/admin',
            '/admin/employees',
            "/admin/employees/{$evil->id}",
            "/admin/employees/{$evil->id}?tab=employment",
            "/admin/employees/{$evil->id}?tab=activity",
            "/admin/employees/{$evil->id}/edit",
            '/admin/departments',
            '/admin/organization',
            '/admin/employees?q='.urlencode(self::SCRIPT),
        ];

        foreach ($pages as $page) {
            $html = $this->get($page)->assertOk()->getContent();
            $this->assertStringNotContainsString(self::SCRIPT, $html, "Unescaped script on {$page}");
            $this->assertStringNotContainsString('<img src=x onerror', $html, "Unescaped markup on {$page}");
        }

        $this->get("/admin/employees/{$evil->id}")->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        $this->get('/admin/departments')->assertSee('&quot;&gt;&lt;img src=x onerror=alert(2)&gt;', false);
        $this->assertNotNull($department);
    }

    public function test_flash_messages_escape_names_too(): void
    {
        $this->signIn('hr');

        $response = $this->post('/admin/employees', [
            'employee_number' => 'EMP-9999', 'full_name' => self::SCRIPT, 'join_date' => '2026-09-01', 'employment_type' => 'permanent', 'status' => 'ACTIVE',
            'department_id' => $this->department->id, 'position_id' => $this->position->id,
        ]);

        $html = $this->followRedirects($response)->getContent();
        $this->assertStringNotContainsString(self::SCRIPT, $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
