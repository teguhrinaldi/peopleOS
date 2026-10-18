<?php

namespace Tests\Feature\Api;

use App\Enums\AttendanceStatus;
use App\Models\Activity;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WorkplaceFixtures;
use Tests\TestCase;

class CorrectionTest extends TestCase
{
    use RefreshDatabase, WorkplaceFixtures;

    private const BASE = '/api/v1/attendance-corrections';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkplace();
        $this->at('2026-09-30 12:00'); // Wednesday
    }

    /** Employee checked in on Tue 09-29 at 08:02 and forgot to check out. */
    private function forgottenCheckout(): array
    {
        [$manager, $managerEmployee] = $this->makeStaff('manager');
        [$user, $employee] = $this->makeStaff('employee', [], ['manager_id' => $managerEmployee->id]);

        $this->at('2026-09-29 08:02');
        $this->actingAsApi($user)->postJson('/api/v1/attendance/check-in', $this->capture('corr-in-key-01'))->assertCreated();
        $this->at('2026-09-30 12:00');

        return [$user, $employee, $manager, $managerEmployee];
    }

    private function requestCorrection(array $overrides = [])
    {
        return $this->postJson(self::BASE, array_merge([
            'work_date' => '2026-09-29', 'requested_check_out_at' => '2026-09-29T17:05:00+07:00', 'reason' => 'Forgot to check out.',
        ], $overrides));
    }

    public function test_employee_requests_a_correction_and_the_attendance_becomes_correction_requested(): void
    {
        [$user, $employee] = $this->forgottenCheckout();

        $this->actingAsApi($user);
        $this->requestCorrection()
            ->assertCreated()
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.requested_check_out_time', '17:05');

        $attendance = Attendance::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame(AttendanceStatus::CorrectionRequested, $attendance->status);
        $this->assertSame(AttendanceStatus::CheckedIn, $attendance->status_before_correction);
    }

    public function test_a_second_pending_correction_for_the_same_day_is_a_conflict(): void
    {
        [$user] = $this->forgottenCheckout();
        $this->actingAsApi($user);
        $this->requestCorrection()->assertCreated();

        $this->requestCorrection(['reason' => 'Trying again please'])->assertStatus(409)->assertJsonPath('error.code', 'CORRECTION_ALREADY_PENDING');
    }

    public function test_correction_validation_rules(): void
    {
        [$user] = $this->forgottenCheckout();
        $this->actingAsApi($user);

        $this->postJson(self::BASE, ['work_date' => '2026-09-29', 'reason' => 'no times given'])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->requestCorrection(['work_date' => '2026-10-05'])->assertStatus(422)->assertJsonPath('error.code', 'INVALID_WORK_DATE');
        $this->requestCorrection(['work_date' => '2026-07-01'])->assertStatus(422)->assertJsonPath('error.code', 'CORRECTION_WINDOW_EXPIRED');
        $this->requestCorrection(['requested_check_out_at' => '2026-09-29T07:00:00+07:00'])->assertStatus(422)->assertJsonPath('error.code', 'INVALID_EVENT_TIME'); // before check-in
        $this->requestCorrection(['requested_check_out_at' => '2026-09-30T06:00:00+07:00'])->assertStatus(422)->assertJsonPath('error.code', 'INVALID_EVENT_TIME'); // far from the shift
        $this->requestCorrection(['reason' => 'ab'])->assertStatus(422);
    }

    public function test_a_missing_day_can_be_corrected_only_with_a_check_in_time(): void
    {
        [$user] = $this->makeStaff();
        $this->actingAsApi($user);

        $this->postJson(self::BASE, ['work_date' => '2026-09-28', 'requested_check_out_at' => '2026-09-28T17:00:00+07:00', 'reason' => 'Was there all day'])
            ->assertStatus(422)->assertJsonPath('error.code', 'CHECK_IN_REQUIRED');

        $this->postJson(self::BASE, [
            'work_date' => '2026-09-28', 'requested_check_in_at' => '2026-09-28T08:00:00+07:00',
            'requested_check_out_at' => '2026-09-28T17:00:00+07:00', 'reason' => 'App was down all day.',
        ])->assertCreated();
    }

    public function test_employee_cannot_approve_their_own_correction_even_with_the_permission(): void
    {
        [$user, $employee] = $this->forgottenCheckout();
        $user->assignRole('hr'); // holds attendance.correct
        $user->forgetPermissionCache();
        $this->actingAsApi($user);
        $id = $this->requestCorrection()->json('data.id');

        $this->postJson(self::BASE."/{$id}/approve")->assertForbidden();
        $this->postJson(self::BASE."/{$id}/reject", ['note' => 'nope'])->assertForbidden();
        $this->assertSame('PENDING', AttendanceCorrection::query()->find($id)->status->value);
    }

    public function test_direct_manager_approves_and_the_attendance_is_corrected_and_audited(): void
    {
        [$user, $employee, $manager] = $this->forgottenCheckout();
        $id = $this->actingAsApi($user)->requestCorrection()->json('data.id');

        $this->actingAsApi($manager)->postJson(self::BASE."/{$id}/approve", ['note' => 'OK'])
            ->assertOk()->assertJsonPath('data.status', 'APPROVED')->assertJsonPath('data.review_note', 'OK');

        $attendance = Attendance::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame(AttendanceStatus::Corrected, $attendance->status);
        $this->assertSame('10:05:00', $attendance->check_out_at->format('H:i:s')); // 17:05 WIB
        $this->assertSame(483, $attendance->worked_minutes); // 08:02 -> 17:05 = 543 min - 60 break
        $this->assertNull($attendance->status_before_correction);
        $this->assertTrue(Activity::query()->where('action', 'attendance_correction.approved')->where('actor_id', $manager->id)->exists());

        // A decided request cannot be decided twice.
        $this->postJson(self::BASE."/{$id}/approve")->assertStatus(409)->assertJsonPath('error.code', 'REQUEST_NOT_PENDING');
    }

    public function test_a_manager_cannot_review_someone_outside_their_team_but_hr_can(): void
    {
        [$user] = $this->forgottenCheckout();
        [$otherManager] = $this->makeStaff('manager');
        [$hr] = $this->makeStaff('hr');
        $id = $this->actingAsApi($user)->requestCorrection()->json('data.id');

        $this->actingAsApi($otherManager)->postJson(self::BASE."/{$id}/approve")->assertForbidden();
        $this->getJson(self::BASE."/{$id}")->assertForbidden();
        $this->getJson(self::BASE.'?scope=review')->assertOk()->assertJsonCount(0, 'data');

        $this->actingAsApi($hr);
        $this->getJson(self::BASE.'?scope=review&status=PENDING')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson(self::BASE."/{$id}/approve")->assertOk();
    }

    public function test_rejection_requires_a_reason_and_restores_the_attendance(): void
    {
        [$user, $employee, $manager] = $this->forgottenCheckout();
        $id = $this->actingAsApi($user)->requestCorrection()->json('data.id');

        $this->actingAsApi($manager);
        $this->postJson(self::BASE."/{$id}/reject")->assertStatus(422);
        $this->postJson(self::BASE."/{$id}/reject", ['note' => 'You left at 16:00, I saw you.'])
            ->assertOk()->assertJsonPath('data.status', 'REJECTED');

        $attendance = Attendance::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame(AttendanceStatus::CheckedIn, $attendance->status);
        $this->assertNull($attendance->check_out_at);
    }

    public function test_only_the_owner_can_cancel_and_only_while_pending(): void
    {
        [$user, , $manager] = $this->forgottenCheckout();
        $id = $this->actingAsApi($user)->requestCorrection()->json('data.id');

        $this->actingAsApi($manager)->postJson(self::BASE."/{$id}/cancel")->assertForbidden();
        $this->actingAsApi($user)->postJson(self::BASE."/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->assertSame(AttendanceStatus::CheckedIn, Attendance::query()->firstOrFail()->status);
        $this->postJson(self::BASE."/{$id}/cancel")->assertStatus(409)->assertJsonPath('error.code', 'REQUEST_NOT_PENDING');
    }

    public function test_employee_cannot_read_someone_elses_correction(): void
    {
        [$user] = $this->forgottenCheckout();
        [$stranger] = $this->makeStaff();
        $id = $this->actingAsApi($user)->requestCorrection()->json('data.id');

        $this->actingAsApi($stranger)->getJson(self::BASE."/{$id}")->assertForbidden();
        $this->getJson(self::BASE)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::BASE.'?scope=review')->assertForbidden();
    }

    public function test_terminated_employee_cannot_request_a_correction(): void
    {
        [$user, $employee] = $this->forgottenCheckout();
        $employee->update(['status' => 'TERMINATED']);
        $user->refresh(); // drop the cached employee relation, as a fresh request would

        $this->actingAsApi($user)->requestCorrection()->assertForbidden()->assertJsonPath('error.code', 'EMPLOYEE_NOT_ACTIVE');
    }
}
