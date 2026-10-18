<?php

namespace Tests\Feature\Api;

use App\Models\Activity;
use App\Models\AppNotification;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\User;
use App\Services\EmployeeService;
use App\Services\Leave\LeaveLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LeaveFixtures;
use Tests\Support\WorkplaceFixtures;
use Tests\TestCase;

class LeaveTest extends TestCase
{
    use LeaveFixtures, RefreshDatabase, WorkplaceFixtures;

    private const BASE = '/api/v1/leave-requests';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkplace();
        $this->setUpLeave();
        $this->at('2026-09-30 09:00'); // Wednesday
    }

    /** @return array{0: User, 1: Employee, 2: User} employee, record, their manager */
    private function teamMember(): array
    {
        [$manager, $managerEmployee] = $this->makeStaff('manager');
        [$user, $employee] = $this->makeStaff('employee', [], ['manager_id' => $managerEmployee->id]);

        return [$user, $employee, $manager];
    }

    private function balance(int $employeeId): LeaveBalance
    {
        return LeaveBalance::query()->where(['employee_id' => $employeeId, 'leave_type_id' => $this->annual->id, 'year' => 2026])->firstOrFail();
    }

    public function test_types_and_balances_are_listed_for_the_employee(): void
    {
        [$user] = $this->teamMember();
        $this->actingAsApi($user);

        $this->getJson('/api/v1/leave-types')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.policy.annual_allowance', '12.0');
        $this->getJson('/api/v1/leave-balances')->assertOk()
            ->assertJsonPath('meta.year', 2026)
            ->assertJsonFragment(['available' => '12.0', 'used' => '0.0', 'reserved' => '0.0']);
    }

    public function test_working_days_only_are_counted_and_the_balance_is_reserved_not_consumed(): void
    {
        [$user, $employee] = $this->teamMember();
        Holiday::query()->create(['company_id' => $this->company->id, 'date' => '2026-10-06', 'name' => 'Local holiday']);

        // Fri 2 Oct .. Wed 7 Oct = Fri, Mon, (Tue holiday), Wed = 3 working days
        $this->actingAsApi($user)->postJson(self::BASE, $this->leavePayload($this->annual, '2026-10-02', '2026-10-07'))
            ->assertCreated()
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.days', '3.0');

        $balance = $this->balance($employee->id);
        $this->assertSame('3.0', (string) $balance->reserved);
        $this->assertSame('0.0', (string) $balance->used);
        $this->assertSame('9.0', $balance->available());
    }

    public function test_preview_reports_days_and_the_balance_after_without_writing(): void
    {
        [$user] = $this->teamMember();
        $this->actingAsApi($user)->postJson(self::BASE.'/preview', $this->leavePayload($this->annual, '2026-10-05', '2026-10-09'))
            ->assertOk()->assertJsonPath('data.days', '5.0')->assertJsonPath('data.available_after', '7.0');

        $this->assertSame(0, LeaveRequest::query()->count());
    }

    public function test_half_day_counts_half_and_frees_the_other_half(): void
    {
        [$user, $employee] = $this->teamMember();
        $this->actingAsApi($user);

        $this->postJson(self::BASE, $this->leavePayload($this->annual, '2026-10-05', null, ['half_day' => 'AM']))->assertCreated()->assertJsonPath('data.days', '0.5');
        $this->postJson(self::BASE, $this->leavePayload($this->annual, '2026-10-05', null, ['half_day' => 'PM']))->assertCreated();
        $this->postJson(self::BASE, $this->leavePayload($this->annual, '2026-10-05', null, ['half_day' => 'AM']))->assertStatus(409)->assertJsonPath('error.code', 'LEAVE_OVERLAP');
        $this->postJson(self::BASE, $this->leavePayload($this->annual, '2026-10-05', '2026-10-06', ['half_day' => 'AM']))->assertStatus(422)->assertJsonPath('error.code', 'HALF_DAY_SINGLE_DATE');

        $this->assertSame('1.0', (string) $this->balance($employee->id)->reserved);
    }

    public function test_overlapping_leave_is_refused(): void
    {
        [$user] = $this->teamMember();
        $this->actingAsApi($user);

        $this->postJson(self::BASE, $this->leavePayload($this->annual, '2026-10-05', '2026-10-09'))->assertCreated();
        $this->postJson(self::BASE, $this->leavePayload($this->sick, '2026-10-08', '2026-10-12', ['reason' => 'Flu']))
            ->assertStatus(409)->assertJsonPath('error.code', 'LEAVE_OVERLAP');
    }

    public function test_rejected_or_cancelled_leave_frees_the_dates_again(): void
    {
        [$user, $employee, $manager] = $this->teamMember();
        $id = $this->actingAsApi($user)->postJson(self::BASE, $this->leavePayload($this->annual, '2026-10-05'))->json('data.id');

        $this->actingAsApi($manager)->postJson(self::BASE."/{$id}/reject", ['note' => 'Busy week'])->assertOk()->assertJsonPath('data.status', 'REJECTED');
        $this->assertSame('0.0', (string) $this->balance($employee->id)->reserved);

        $this->actingAsApi($user)->postJson(self::BASE, $this->leavePayload($this->annual, '2026-10-05'))->assertCreated();
    }

    public function test_insufficient_balance_is_refused_unless_the_policy_allows_negative(): void
    {
        [$user] = $this->teamMember();
        $this->actingAsApi($user);

        // 15 working days > 12 available
        $this->postJson(self::BASE, $this->leavePayload($this->annual, '2026-10-05', '2026-10-23'))
            ->assertStatus(422)->assertJsonPath('error.code', 'LEAVE_INSUFFICIENT_BALANCE')->assertJsonPath('error.details.available', '12.0');

        $this->annual->policies()->update(['allow_negative' => true, 'max_negative_days' => 3]);
        $this->postJson(self::BASE, $this->leavePayload($this->annual, '2026-10-05', '2026-10-23'))->assertCreated()->assertJsonPath('data.days', '15.0');
    }

    public function test_pending_requests_reserve_balance_so_two_cannot_consume_the_same_days(): void
    {
        [$user] = $this->teamMember();
        $this->actingAsApi($user);

        $this->postJson(self::BASE, $this->leavePayload($this->annual, '2026-10-05', '2026-10-14'))->assertCreated(); // 8 days
        $this->postJson(self::BASE, $this->leavePayload($this->annual, '2026-11-02', '2026-11-06'))                    // 5 more > 4 left
            ->assertStatus(422)->assertJsonPath('error.code', 'LEAVE_INSUFFICIENT_BALANCE');
    }

    public function test_manager_approves_and_the_reserve_becomes_used_with_notification_and_audit(): void
    {
        [$user, $employee, $manager] = $this->teamMember();
        $id = $this->actingAsApi($user)->postJson(self::BASE, $this->leavePayload($this->annual, '2026-10-05', '2026-10-06'))->json('data.id');

        $this->assertTrue(AppNotification::query()->where('user_id', $manager->id)->where('type', 'leave.pending_review')->exists());

        $this->actingAsApi($manager)->postJson(self::BASE."/{$id}/approve", ['note' => 'Enjoy'])
            ->assertOk()->assertJsonPath('data.status', 'APPROVED')->assertJsonPath('data.reviewer.id', $manager->id);

        $balance = $this->balance($employee->id);
        $this->assertSame('0.0', (string) $balance->reserved);
        $this->assertSame('2.0', (string) $balance->used);
        $this->assertTrue(AppNotification::query()->where('user_id', $user->id)->where('type', 'leave.approved')->exists());
        $this->assertTrue(Activity::query()->where('action', 'leave.approved')->where('actor_id', $manager->id)->exists());

        $this->postJson(self::BASE."/{$id}/approve")->assertStatus(409)->assertJsonPath('error.code', 'REQUEST_NOT_PENDING');
    }

    public function test_employee_cannot_approve_their_own_leave_even_with_the_permission(): void
    {
        [$hr] = $this->makeStaff('hr');
        $id = $this->actingAsApi($hr)->postJson(self::BASE, $this->leavePayload($this->annual, '2026-10-05'))->json('data.id');

        $this->postJson(self::BASE."/{$id}/approve")->assertForbidden();
        $this->postJson(self::BASE."/{$id}/reject", ['note' => 'x'])->assertForbidden();
        $this->assertSame('PENDING', LeaveRequest::query()->find($id)->status->value);
    }

    public function test_manager_cannot_approve_outside_their_team_and_cannot_see_its_state(): void
    {
        [$user] = $this->teamMember();
        [$otherManager] = $this->makeStaff('manager');
        $id = $this->actingAsApi($user)->postJson(self::BASE, $this->leavePayload($this->annual, '2026-10-05'))->json('data.id');

        $this->actingAsApi($otherManager);
        $this->postJson(self::BASE."/{$id}/approve")->assertForbidden();
        $this->getJson(self::BASE."/{$id}")->assertForbidden();
        $this->getJson(self::BASE.'?scope=review')->assertOk()->assertJsonCount(0, 'data');

        // Tampering with employee_id on the list does not widen the scope either.
        $this->getJson(self::BASE.'?scope=all&employee_id='.$user->employee->id)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_hr_can_review_anyone(): void
    {
        [$user] = $this->teamMember();
        [$hr] = $this->makeStaff('hr');
        $id = $this->actingAsApi($user)->postJson(self::BASE, $this->leavePayload($this->annual, '2026-10-05'))->json('data.id');

        $this->actingAsApi($hr)->getJson(self::BASE.'?scope=review&status=PENDING')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson(self::BASE."/{$id}/approve")->assertOk();
    }

    public function test_rejection_requires_a_reason_the_employee_can_read(): void
    {
        [$user, , $manager] = $this->teamMember();
        $id = $this->actingAsApi($user)->postJson(self::BASE, $this->leavePayload($this->annual, '2026-10-05'))->json('data.id');

        $this->actingAsApi($manager)->postJson(self::BASE."/{$id}/reject")->assertStatus(422);
        $this->postJson(self::BASE."/{$id}/reject", ['note' => 'Quarter-end close'])->assertOk();

        $this->actingAsApi($user)->getJson(self::BASE."/{$id}")
            ->assertOk()->assertJsonPath('data.status', 'REJECTED')->assertJsonPath('data.review_note', 'Quarter-end close')
            ->assertJsonPath('data.leave_type.name', 'Annual Leave')->assertJsonPath('data.start_date', '2026-10-05');
    }

    public function test_employee_cannot_read_someone_elses_leave(): void
    {
        [$user] = $this->teamMember();
        [$stranger] = $this->makeStaff();
        $id = $this->actingAsApi($user)->postJson(self::BASE, $this->leavePayload($this->annual, '2026-10-05'))->json('data.id');

        $this->actingAsApi($stranger);
        $this->getJson(self::BASE."/{$id}")->assertForbidden();
        $this->postJson(self::BASE."/{$id}/cancel")->assertForbidden();
        $this->getJson(self::BASE.'?scope=all')->assertForbidden();
        $this->getJson('/api/v1/leave-balances?employee_id='.$user->employee->id)->assertForbidden();
    }

    public function test_drafts_hold_nothing_and_can_be_edited_then_submitted(): void
    {
        [$user, $employee, $manager] = $this->teamMember();
        $this->actingAsApi($user);

        $id = $this->postJson(self::BASE, $this->leavePayload($this->annual, '2026-10-05') + ['submit' => false])->assertCreated()->assertJsonPath('data.status', 'DRAFT')->json('data.id');
        $this->assertSame(0, LeaveRequestDay::query()->count());
        $this->patchJson(self::BASE."/{$id}", ['end_date' => '2026-10-06'])->assertOk();
        $this->postJson(self::BASE."/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'PENDING')->assertJsonPath('data.days', '2.0');

        $this->patchJson(self::BASE."/{$id}", ['end_date' => '2026-10-07'])->assertStatus(409);
        $this->actingAsApi($manager)->getJson(self::BASE.'?scope=review')->assertJsonCount(1, 'data');
        $this->assertSame('2.0', (string) $this->balance($employee->id)->reserved);
    }

    public function test_approved_leave_can_be_cancelled_before_it_starts_only(): void
    {
        [$user, $employee, $manager] = $this->teamMember();
        $future = $this->actingAsApi($user)->postJson(self::BASE, $this->leavePayload($this->annual, '2026-10-05'))->json('data.id');
        $this->actingAsApi($manager)->postJson(self::BASE."/{$future}/approve")->assertOk();

        $this->actingAsApi($user)->postJson(self::BASE."/{$future}/cancel", ['reason' => 'Plans changed'])->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->assertSame('0.0', (string) $this->balance($employee->id)->used);

        $today = $this->postJson(self::BASE, $this->leavePayload($this->annual, '2026-09-30'))->json('data.id');
        $this->actingAsApi($manager)->postJson(self::BASE."/{$today}/approve")->assertOk();
        $this->actingAsApi($user)->postJson(self::BASE."/{$today}/cancel")->assertStatus(409)->assertJsonPath('error.code', 'LEAVE_ALREADY_STARTED');
    }

    public function test_policy_rules_notice_past_dates_reason_and_year_boundary(): void
    {
        [$user] = $this->teamMember();
        $this->actingAsApi($user);
        $this->annual->policies()->update(['min_notice_days' => 3]);

        $this->postJson(self::BASE, $this->leavePayload($this->annual, '2026-10-01'))->assertStatus(422)->assertJsonPath('error.code', 'LEAVE_NOTICE_TOO_SHORT');
        $this->postJson(self::BASE, $this->leavePayload($this->annual, '2026-09-28'))->assertStatus(422)->assertJsonPath('error.code', 'LEAVE_TOO_FAR_IN_PAST');
        $this->postJson(self::BASE, $this->leavePayload($this->sick, '2026-09-28', '2026-09-29', ['reason' => 'Fever']))->assertCreated(); // sick: back-dating allowed
        $this->postJson(self::BASE, $this->leavePayload($this->sick, '2026-10-12', null, ['reason' => '']))->assertStatus(422);
        $this->postJson(self::BASE, $this->leavePayload($this->annual, '2026-12-28', '2027-01-04'))->assertStatus(422)->assertJsonPath('error.code', 'LEAVE_SPANS_YEARS');
        $this->postJson(self::BASE, $this->leavePayload($this->annual, '2026-10-10', '2026-10-11'))->assertStatus(422)->assertJsonPath('error.code', 'LEAVE_NO_WORKING_DAYS'); // weekend
        $this->postJson(self::BASE, $this->leavePayload($this->annual, '2026-10-09', '2026-10-05'))->assertStatus(422)->assertJsonPath('error.code', 'INVALID_DATE_RANGE');
    }

    public function test_unlimited_types_track_usage_and_payroll_can_read_unpaid_days(): void
    {
        [$user, $employee, $manager] = $this->teamMember();
        $id = $this->actingAsApi($user)->postJson(self::BASE, $this->leavePayload($this->unpaid, '2026-10-05', '2026-10-07', ['reason' => 'Moving house']))->assertCreated()->json('data.id');
        $this->actingAsApi($manager)->postJson(self::BASE."/{$id}/approve")->assertOk();

        $ledger = app(LeaveLedger::class);
        $this->assertSame('3.0', $ledger->unpaidDaysBetween($employee, '2026-10-01', '2026-10-31'));
        $this->assertSame('2.0', $ledger->unpaidDaysBetween($employee, '2026-10-06', '2026-10-31'));
        $this->assertSame('0.0', $ledger->paidDaysBetween($employee, '2026-10-01', '2026-10-31'));
        $this->assertSame('FULL', $ledger->coverageOn($employee, '2026-10-05'));
    }

    public function test_terminated_employee_cannot_request_and_termination_releases_future_leave(): void
    {
        [$user, $employee, $manager] = $this->teamMember();
        [$hr] = $this->makeStaff('hr');
        $pending = $this->actingAsApi($user)->postJson(self::BASE, $this->leavePayload($this->annual, '2026-11-02'))->json('data.id');

        app(EmployeeService::class)->terminate($employee, '2026-10-15', 'Resigned', $hr);

        $this->assertSame('CANCELLED', LeaveRequest::query()->find($pending)->status->value);
        $this->assertSame('0.0', (string) $this->balance($employee->id)->reserved);

        $user->refresh();
        $this->postJson(self::BASE, $this->leavePayload($this->annual, '2026-10-05'))->assertForbidden();
    }

    public function test_approved_full_day_leave_blocks_check_in_and_shows_on_home(): void
    {
        [$user, , $manager] = $this->teamMember();
        $id = $this->actingAsApi($user)->postJson(self::BASE, $this->leavePayload($this->annual, '2026-09-30'))->json('data.id');
        $this->actingAsApi($manager)->postJson(self::BASE."/{$id}/approve")->assertOk();

        $this->actingAsApi($user);
        $this->getJson('/api/v1/attendance/today')->assertJsonPath('data.state', 'ON_LEAVE')->assertJsonPath('data.can_check_in', false);
        $this->postJson('/api/v1/attendance/check-in', $this->capture('leave-day-key1'))->assertStatus(409)->assertJsonPath('error.code', 'ON_LEAVE');
    }

    public function test_leave_type_administration_requires_leave_manage(): void
    {
        [$manager] = $this->makeStaff('manager');
        [$hr] = $this->makeStaff('hr');

        $this->actingAsApi($manager)->getJson('/api/v1/leave-setup/types')->assertForbidden();

        $this->actingAsApi($hr)->postJson('/api/v1/leave-setup/types', [
            'code' => 'MAT', 'name' => 'Maternity Leave', 'is_paid' => true,
            'policy' => ['annual_allowance' => 90, 'allow_half_day' => false, 'max_consecutive_days' => 90],
        ])->assertCreated()->assertJsonPath('data.name', 'Maternity Leave');

        $this->postJson('/api/v1/leave-setup/types', ['code' => 'MAT', 'name' => 'Dup', 'is_paid' => true, 'policy' => []])->assertStatus(422);
    }

    public function test_hr_balance_adjustment_is_audited_and_cannot_go_negative(): void
    {
        [$user, $employee] = $this->teamMember();
        [$hr] = $this->makeStaff('hr');
        $this->actingAsApi($user)->getJson('/api/v1/leave-balances')->assertOk();
        $balance = $this->balance($employee->id);

        $this->actingAsApi($hr)->postJson("/api/v1/leave-setup/balances/{$balance->id}/adjust", ['delta' => 2.5, 'reason' => 'Service award'])
            ->assertOk()->assertJsonPath('data.available', '14.5');
        $this->postJson("/api/v1/leave-setup/balances/{$balance->id}/adjust", ['delta' => -20, 'reason' => 'Oops'])
            ->assertStatus(422)->assertJsonPath('error.code', 'LEAVE_INSUFFICIENT_BALANCE');

        $this->assertTrue(Activity::query()->where('action', 'leave_balance.adjusted')->exists());
    }
}
