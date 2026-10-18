<?php

namespace Tests\Feature\Admin;

use App\Models\Holiday;
use App\Services\ActivityLogger;

class PhaseBEscapingTest extends AdminTestCase
{
    private const SCRIPT = '<script>alert(1)</script>';

    public function test_names_and_notes_are_escaped_on_every_phase_b_page(): void
    {
        [$admin] = $this->signIn('admin');
        $this->shift->update(['name' => self::SCRIPT]);
        $this->location->update(['name' => self::SCRIPT, 'address' => self::SCRIPT]);
        $this->policy->update(['name' => self::SCRIPT]);
        Holiday::query()->create(['company_id' => $this->company->id, 'date' => '2026-12-24', 'name' => self::SCRIPT, 'type' => 'company']);
        $employee = $this->person(self::SCRIPT);
        $attendance = $this->attendance($employee, '2026-09-29', status: 'PENDING', review: true, schedule: ['shift_name' => self::SCRIPT, 'location_name' => self::SCRIPT]);
        $this->correctionFor($attendance)->update(['reason' => self::SCRIPT]);
        $this->overtimeFor($employee)->update(['reason' => self::SCRIPT]);
        app(ActivityLogger::class)->log('employee.updated', $employee, ['full_name' => 'x'], ['full_name' => self::SCRIPT], actor: $admin);

        foreach ([
            '/admin/attendance?from=2026-09-29', "/admin/attendance/{$attendance->id}", '/admin/attendance/corrections', '/admin/overtime',
            '/admin/shifts', "/admin/shifts/{$this->shift->id}/edit", '/admin/shifts/holidays?year=2026', '/admin/work-locations',
            "/admin/work-locations/{$this->location->id}/edit", '/admin/work-locations/policies', '/admin/activity',
        ] as $page) {
            $this->assertStringNotContainsString(self::SCRIPT, $this->get($page)->assertOk()->getContent(), "Unescaped markup on {$page}");
        }
    }
}
