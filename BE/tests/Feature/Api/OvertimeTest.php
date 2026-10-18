<?php

namespace Tests\Feature\Api;

use App\Models\OvertimeRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WorkplaceFixtures;
use Tests\TestCase;

class OvertimeTest extends TestCase
{
    use RefreshDatabase, WorkplaceFixtures;

    private const BASE = '/api/v1/overtime-requests';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkplace();
        $this->at('2026-09-30 12:00');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'work_date' => '2026-09-30', 'start_at' => '2026-09-30T17:00:00+07:00', 'end_at' => '2026-09-30T19:00:00+07:00',
            'reason' => 'Release deployment',
        ], $overrides);
    }

    public function test_request_review_and_result_flow(): void
    {
        [$manager, $managerEmployee] = $this->makeStaff('manager');
        [$user] = $this->makeStaff('employee', [], ['manager_id' => $managerEmployee->id]);

        $id = $this->actingAsApi($user)->postJson(self::BASE, $this->payload())
            ->assertCreated()->assertJsonPath('data.status', 'PENDING')->assertJsonPath('data.requested_minutes', 120)
            ->assertJsonPath('data.start_time', '17:00')->json('data.id');

        $this->actingAsApi($manager)->postJson(self::BASE."/{$id}/approve", ['approved_minutes' => 90, 'note' => 'Only 90'])
            ->assertOk()->assertJsonPath('data.status', 'APPROVED')->assertJsonPath('data.approved_minutes', 90);
    }

    public function test_approval_cannot_exceed_the_requested_minutes(): void
    {
        [$hr] = $this->makeStaff('hr');
        [$user] = $this->makeStaff();
        $id = $this->actingAsApi($user)->postJson(self::BASE, $this->payload())->json('data.id');

        $this->actingAsApi($hr)->postJson(self::BASE."/{$id}/approve", ['approved_minutes' => 600])->assertStatus(422);
        $this->assertSame('PENDING', OvertimeRequest::query()->find($id)->status->value);
    }

    public function test_overlapping_and_excessive_requests_are_refused(): void
    {
        [$user] = $this->makeStaff();
        $this->actingAsApi($user);
        $this->postJson(self::BASE, $this->payload())->assertCreated();

        $this->postJson(self::BASE, $this->payload(['start_at' => '2026-09-30T18:00:00+07:00', 'end_at' => '2026-09-30T20:00:00+07:00']))
            ->assertStatus(409)->assertJsonPath('error.code', 'OVERTIME_OVERLAP');
        $this->postJson(self::BASE, $this->payload(['start_at' => '2026-10-01T06:00:00+07:00', 'end_at' => '2026-10-01T23:00:00+07:00', 'work_date' => '2026-10-01']))
            ->assertStatus(422)->assertJsonPath('error.code', 'OVERTIME_TOO_LONG');
        $this->postJson(self::BASE, $this->payload(['end_at' => '2026-09-30T16:00:00+07:00']))->assertStatus(422);
        $this->postJson(self::BASE, $this->payload(['work_date' => '2027-01-01']))->assertStatus(422);
    }

    public function test_own_request_cannot_be_approved_by_its_owner_and_rejection_needs_a_reason(): void
    {
        [$hr, $hrEmployee] = $this->makeStaff('hr');
        [$user] = $this->makeStaff();

        $ownId = $this->actingAsApi($hr)->postJson(self::BASE, $this->payload())->json('data.id');
        $this->postJson(self::BASE."/{$ownId}/approve")->assertForbidden();

        $id = $this->actingAsApi($user)->postJson(self::BASE, $this->payload(['start_at' => '2026-10-01T17:00:00+07:00', 'end_at' => '2026-10-01T18:00:00+07:00', 'work_date' => '2026-10-01']))->json('data.id');
        $this->actingAsApi($hr)->postJson(self::BASE."/{$id}/reject")->assertStatus(422);
        $this->postJson(self::BASE."/{$id}/reject", ['note' => 'Not budgeted'])->assertOk()->assertJsonPath('data.status', 'REJECTED');
    }

    public function test_manager_only_reviews_direct_reports_and_employees_only_see_their_own(): void
    {
        [$manager] = $this->makeStaff('manager');
        [$user] = $this->makeStaff();
        [$other] = $this->makeStaff();
        $id = $this->actingAsApi($user)->postJson(self::BASE, $this->payload())->json('data.id');

        $this->actingAsApi($manager)->postJson(self::BASE."/{$id}/approve")->assertForbidden();
        $this->actingAsApi($other)->getJson(self::BASE."/{$id}")->assertForbidden();
        $this->getJson(self::BASE)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::BASE.'?scope=review')->assertForbidden();
    }

    public function test_owner_can_cancel_while_pending(): void
    {
        [$user] = $this->makeStaff();
        $id = $this->actingAsApi($user)->postJson(self::BASE, $this->payload())->json('data.id');

        $this->postJson(self::BASE."/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->postJson(self::BASE."/{$id}/cancel")->assertStatus(409)->assertJsonPath('error.code', 'REQUEST_NOT_PENDING');
    }
}
