<?php

namespace Tests\Feature\Api;

use App\Models\AppNotification;
use App\Services\Notifications\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WorkplaceFixtures;
use Tests\TestCase;

class NotificationAndPrivacyTest extends TestCase
{
    use RefreshDatabase, WorkplaceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkplace();
    }

    public function test_inbox_lists_only_own_notifications_and_tracks_read_state(): void
    {
        [$alice] = $this->makeStaff();
        [$bob] = $this->makeStaff();
        $service = app(NotificationService::class);
        $service->notify($alice, 'leave.approved', 'Leave approved');
        $service->notify($alice, 'payslip.available', 'Payslip available');
        $service->notify($bob, 'leave.rejected', 'Leave rejected');
        $bobsId = AppNotification::query()->where('user_id', $bob->id)->value('id');

        $this->actingAsApi($alice);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.unread_count', 2);

        $id = AppNotification::query()->where('user_id', $alice->id)->value('id');
        $this->postJson("/api/v1/notifications/{$id}/read")->assertOk()->assertJsonPath('data.is_read', true);
        $this->getJson('/api/v1/notifications/unread-count')->assertJsonPath('data.unread_count', 1);

        // Someone else's notification is invisible and untouched.
        $this->postJson("/api/v1/notifications/{$bobsId}/read")->assertStatus(404);
        $this->assertNull(AppNotification::query()->find($bobsId)->read_at);

        $this->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.marked', 1);
    }

    public function test_dedupe_key_prevents_double_notifications_and_inactive_users_get_none(): void
    {
        [$alice] = $this->makeStaff();
        [$disabled] = $this->makeStaff();
        $disabled->update(['is_active' => false]);
        $service = app(NotificationService::class);

        $service->notify($alice, 'document.expiring', 'Document expiring', dedupeKey: 'doc-1-expiring');
        $service->notify($alice, 'document.expiring', 'Document expiring', dedupeKey: 'doc-1-expiring');
        $service->notify($disabled, 'leave.approved', 'x');

        $this->assertSame(1, AppNotification::query()->where('user_id', $alice->id)->count());
        $this->assertSame(0, AppNotification::query()->where('user_id', $disabled->id)->count());
    }

    public function test_manager_sees_team_member_but_not_their_personal_data(): void
    {
        [$manager, $managerEmployee] = $this->makeStaff('manager');
        [, $report] = $this->makeStaff('employee', ['date_of_birth' => '1990-05-05', 'phone' => '+62 811 1111 2222', 'address' => 'Jl. Private 1'], ['manager_id' => $managerEmployee->id]);
        [$hr] = $this->makeStaff('hr');

        $this->actingAsApi($manager)->getJson("/api/v1/employees/{$report->id}")
            ->assertOk()->assertJsonPath('data.full_name', $report->full_name)
            ->assertJsonPath('data.date_of_birth', null)->assertJsonPath('data.phone', null)->assertJsonPath('data.address', null);

        $this->actingAsApi($hr)->getJson("/api/v1/employees/{$report->id}")
            ->assertJsonPath('data.date_of_birth', '1990-05-05')->assertJsonPath('data.phone', '+62 811 1111 2222');
    }

    public function test_fake_company_id_or_employee_id_in_a_body_is_ignored(): void
    {
        [$alice, $aliceEmployee] = $this->makeStaff();
        [, $bob] = $this->makeStaff();
        $this->at('2026-09-30 08:00');

        $this->actingAsApi($alice)->postJson('/api/v1/attendance/check-in', $this->capture('fake-ids-key-1', extra: [
            'employee_id' => $bob->id, 'company_id' => 999,
        ]))->assertCreated()->assertJsonPath('data.attendance.employee_id', $aliceEmployee->id);

        $this->postJson('/api/v1/overtime-requests', [
            'employee_id' => $bob->id, 'company_id' => 999, 'work_date' => '2026-09-30',
            'start_at' => '2026-09-30T17:00:00+07:00', 'end_at' => '2026-09-30T18:00:00+07:00', 'reason' => 'Deploy window',
        ])->assertCreated()->assertJsonPath('data.employee.id', $aliceEmployee->id);
    }
}
