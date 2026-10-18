<?php

namespace Tests\Feature\Api;

use App\Enums\GeofenceMode;
use App\Models\Attendance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WorkplaceFixtures;
use Tests\TestCase;

/** HR review of attendance flagged by the "review" geofence mode. */
class AttendanceReviewTest extends TestCase
{
    use RefreshDatabase, WorkplaceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkplace();
        $this->policy->update(['geofence_mode' => GeofenceMode::Review]);
    }

    private function flaggedAttendance(): array
    {
        [$user] = $this->makeStaff();
        $this->at('2026-09-30 08:00');
        $id = $this->actingAsApi($user)->postJson('/api/v1/attendance/check-in', $this->capture('rv-key-000001', -6.95, 107.6071))
            ->assertCreated()->assertJsonPath('data.attendance.status', 'PENDING')->json('data.attendance.id');

        return [$user, $id];
    }

    public function test_hr_approves_flagged_attendance_and_it_returns_to_the_normal_lifecycle(): void
    {
        [, $id] = $this->flaggedAttendance();
        [$hr] = $this->makeStaff('hr');

        $this->actingAsApi($hr)->postJson("/api/v1/attendance/{$id}/approve", ['note' => 'Client visit'])
            ->assertOk()->assertJsonPath('data.status', 'CHECKED_IN')->assertJsonPath('data.needs_review', false);
    }

    public function test_hr_rejects_with_a_required_reason(): void
    {
        [, $id] = $this->flaggedAttendance();
        [$hr] = $this->makeStaff('hr');
        $this->actingAsApi($hr);

        $this->postJson("/api/v1/attendance/{$id}/reject")->assertStatus(422);
        $this->postJson("/api/v1/attendance/{$id}/reject", ['note' => 'Was at home'])
            ->assertOk()->assertJsonPath('data.status', 'REJECTED')->assertJsonPath('data.review_note', 'Was at home');

        $this->postJson("/api/v1/attendance/{$id}/approve")->assertStatus(409)->assertJsonPath('error.code', 'REQUEST_NOT_PENDING');
    }

    public function test_employees_and_managers_cannot_review(): void
    {
        [$owner, $id] = $this->flaggedAttendance();
        [$manager] = $this->makeStaff('manager');

        $this->actingAsApi($owner)->postJson("/api/v1/attendance/{$id}/approve")->assertForbidden();
        $this->actingAsApi($manager)->postJson("/api/v1/attendance/{$id}/approve")->assertForbidden();
        $this->assertSame('PENDING', Attendance::query()->findOrFail($id)->status->value);
    }

    public function test_a_flagged_attendance_can_still_be_checked_out_and_stays_pending_until_reviewed(): void
    {
        [$owner, $id] = $this->flaggedAttendance();
        $this->at('2026-09-30 17:00');

        $this->actingAsApi($owner)->postJson('/api/v1/attendance/check-out', $this->capture('rv-out-000001', -6.9219, 107.6072))
            ->assertCreated()->assertJsonPath('data.attendance.status', 'PENDING');

        [$hr] = $this->makeStaff('hr');
        $this->actingAsApi($hr)->postJson("/api/v1/attendance/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'CHECKED_OUT');
    }
}
