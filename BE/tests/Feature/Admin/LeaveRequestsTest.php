<?php

namespace Tests\Feature\Admin;

use App\Enums\LeaveStatus;
use App\Models\Activity;
use App\Models\Department;
use App\Models\LeaveBalance;
use App\Models\LeaveRequestDay;
use App\Models\User;
use App\Services\Leave\LeaveService;
use PHPUnit\Framework\Attributes\DataProvider;

class LeaveRequestsTest extends AdminTestCase
{
    /** @return array<string, array{0: string, 1: int, 2: int}> role => [leave requests status, leave types status] */
    public static function roles(): array
    {
        return [
            'admin' => ['admin', 200, 200],
            'hr' => ['hr', 200, 200],
            'manager' => ['manager', 200, 403],
            'employee' => ['employee', 403, 403],
        ];
    }

    #[DataProvider('roles')]
    public function test_each_role_reaches_only_the_leave_pages_it_may(string $role, int $requests, int $types): void
    {
        $this->signIn($role);

        $this->assertSame($requests, $this->get('/admin/leave-requests')->status());
        $this->assertSame($requests, $this->get('/admin/leave-requests?tab=calendar')->status());
        $this->assertSame($types, $this->get('/admin/leave-types')->status());
        $this->assertSame($types, $this->get('/admin/leave-types/create')->status());
    }

    public function test_leave_is_no_longer_a_placeholder(): void
    {
        $this->signIn('admin');

        $this->get('/admin/leave-requests')->assertOk()->assertDontSee('This module arrives in a later release');
        $this->get('/admin/leave-types')->assertOk()->assertDontSee('This module arrives in a later release');
    }

    public function test_the_queue_is_team_scoped_and_hides_own_requests(): void
    {
        [, $me] = $this->signIn('manager');
        [, $other] = $this->makeStaff('manager');
        $type = $this->leaveType();
        $this->leaveFor($this->person('Report Rita', [], ['manager_id' => $me->id]), $type);
        $this->leaveFor($this->person('Other Olga', [], ['manager_id' => $other->id]), $type, '2026-10-06');
        $this->leaveFor($me, $type, '2026-10-07');

        $names = fn (string $qs = '') => $this->get('/admin/leave-requests'.$qs)->assertOk()->viewData('requests')->getCollection()->map(fn ($r) => $r->employee->full_name)->all();

        $this->assertSame(['Report Rita'], $names());
        $this->assertSame(['Report Rita'], $names('?tab=history'), 'history is scoped the same way');

        $this->signIn('hr');
        $this->assertEqualsCanonicalizing(['Report Rita', 'Other Olga', $me->full_name], $names());
    }

    public function test_a_manager_cannot_decide_leave_outside_the_team_or_their_own(): void
    {
        [, $me] = $this->signIn('manager');
        [, $other] = $this->makeStaff('manager');
        $type = $this->leaveType();
        $theirs = $this->leaveFor($this->person('Other Olga', [], ['manager_id' => $other->id]), $type);
        $own = $this->leaveFor($me, $type, '2026-10-07');

        $this->post("/admin/leave-requests/{$theirs->id}/decision", ['decision' => 'approve'])->assertForbidden();
        $this->post("/admin/leave-requests/{$own->id}/decision", ['decision' => 'approve'])->assertForbidden();
        $this->get("/admin/leave-requests/{$theirs->id}")->assertForbidden();

        $this->assertSame(LeaveStatus::Pending, $theirs->fresh()->status);
        $this->assertSame(LeaveStatus::Pending, $own->fresh()->status);
    }

    public function test_approving_moves_reserved_days_to_used_through_the_leave_service(): void
    {
        [$manager, $me] = $this->signIn('manager');
        $report = $this->person('Report Rita', [], ['manager_id' => $me->id]);
        $request = $this->leaveFor($report, $this->leaveType(), '2026-10-05', '2026-10-07');
        $balance = LeaveBalance::query()->where('employee_id', $report->id)->firstOrFail();
        $this->assertSame('3.0', $balance->reserved);

        $this->post("/admin/leave-requests/{$request->id}/decision", ['decision' => 'approve', 'note' => 'Enjoy'])
            ->assertRedirect('/admin/leave-requests')->assertSessionHas('success');

        $this->assertSame(LeaveStatus::Approved, $request->fresh()->status);
        $this->assertSame('3.0', $balance->fresh()->used);
        $this->assertSame('0.0', $balance->fresh()->reserved);
        $this->assertTrue(Activity::query()->where('action', 'leave.approved')->where('actor_id', $manager->id)->exists());
    }

    public function test_rejecting_needs_a_note_and_gives_the_days_back(): void
    {
        $this->signIn('hr');
        $report = $this->person('Report Rita');
        $request = $this->leaveFor($report, $this->leaveType(), '2026-10-05', '2026-10-06');
        $url = "/admin/leave-requests/{$request->id}/decision";

        $this->post($url, ['decision' => 'reject'])->assertSessionHasErrors(['note' => 'Tell the employee why this is rejected.']);
        $this->post($url, ['decision' => 'reject', 'note' => '  '])->assertSessionHasErrors('note');
        $this->post($url, ['decision' => 'maybe'])->assertSessionHasErrors('decision');
        $this->assertSame(LeaveStatus::Pending, $request->fresh()->status);

        $this->post($url, ['decision' => 'reject', 'note' => 'Team is short-staffed'])->assertSessionHas('success');
        $fresh = $request->fresh();
        $this->assertSame(LeaveStatus::Rejected, $fresh->status);
        $this->assertSame('Team is short-staffed', $fresh->review_note);
        $this->assertSame('0.0', LeaveBalance::query()->where('employee_id', $report->id)->firstOrFail()->reserved);
        $this->assertSame(0, LeaveRequestDay::query()->where('leave_request_id', $request->id)->where('blocking', true)->count(), 'calendar slots released');

        $this->from('/admin/leave-requests')->post($url, ['decision' => 'approve'])->assertSessionHas('error');
    }

    public function test_cancel_releases_the_balance_and_is_refused_once_started(): void
    {
        $this->signIn('hr');
        $type = $this->leaveType();
        $future = $this->leaveFor($this->person('Future Fay'), $type, '2026-10-12');

        $this->post("/admin/leave-requests/{$future->id}/cancel", ['reason' => 'Plans changed'])->assertSessionHas('success');
        $this->assertSame(LeaveStatus::Cancelled, $future->fresh()->status);

        $running = $this->leaveFor($this->person('Running Ray'), $type, '2026-09-30');
        app(LeaveService::class)->approve(auth()->user(), $running);
        $this->from('/admin/leave-requests')->post("/admin/leave-requests/{$running->id}/cancel")->assertSessionHas('error');
        $this->assertSame(LeaveStatus::Approved, $running->fresh()->status);
    }

    public function test_history_filters_by_status_type_employee_department_and_dates(): void
    {
        $this->signIn('hr');
        $annual = $this->leaveType();
        $sick = $this->leaveType('SICK', 'Sick Leave', ['annual_allowance' => null]);
        $rita = $this->person('Report Rita');
        $sam = $this->person('Sales Sam', [], ['department_id' => Department::query()->create(['company_id' => $this->company->id, 'code' => 'SAL', 'name' => 'Sales'])->id]);
        $a = $this->leaveFor($rita, $annual, '2026-10-05');
        $b = $this->leaveFor($sam, $sick, '2026-11-02');
        app(LeaveService::class)->approve(auth()->user(), $b);

        $names = fn (string $qs) => $this->get('/admin/leave-requests?tab=history&'.$qs)->assertOk()->viewData('requests')->getCollection()->map(fn ($r) => $r->employee->full_name)->all();

        $this->assertCount(2, $names(''));
        $this->assertSame(['Sales Sam'], $names('status=APPROVED'));
        $this->assertSame(['Report Rita'], $names('leave_type_id='.$annual->id));
        $this->assertSame(['Sales Sam'], $names('employee_id='.$sam->id));
        $this->assertSame(['Sales Sam'], $names('department_id='.$sam->assignments()->first()->department_id));
        $this->assertSame(['Report Rita'], $names('from=2026-10-01&to=2026-10-31'));
        $this->get('/admin/leave-requests?tab=history&status=DRAFT')->assertSessionHasErrors('status');
        $this->assertNotNull($a);
    }

    public function test_the_detail_panel_shows_days_balance_policy_and_history(): void
    {
        $this->signIn('hr');
        $request = $this->leaveFor($this->person('Report Rita'), $this->leaveType(), '2026-10-02', '2026-10-06', reason: 'Visit family');

        $panel = $this->get("/admin/leave-requests/{$request->id}?panel=1")->assertOk();
        $panel->assertDontSee('<html', false)->assertSee('Visit family')->assertSee('Working dates counted')->assertSee('Policy at submission')->assertSee('History');
        $data = $panel->viewData('dates');
        $this->assertSame(['2026-10-02', '2026-10-05', '2026-10-06'], array_keys($data), 'weekend inside the range is not counted');
        $this->assertSame('3.0', $panel->viewData('days'));
        $this->assertSame('7.0', $panel->viewData('impact')['available']);

        $this->get("/admin/leave-requests/{$request->id}")->assertOk()->assertSee('<html', false);
    }

    public function test_drafts_are_private_and_unknown_requests_404(): void
    {
        $this->signIn('hr');
        $draft = app(LeaveService::class)->create($this->person('Draft Dan'), auth()->user(), [
            'leave_type_id' => $this->leaveType()->id, 'start_date' => '2026-10-20', 'end_date' => '2026-10-20',
        ], submit: false);

        $this->get("/admin/leave-requests/{$draft->id}")->assertNotFound();
        $this->get('/admin/leave-requests/999999')->assertNotFound();
        $this->get('/admin/leave-requests?tab=history')->assertOk()->assertDontSee('Draft Dan');
    }

    public function test_the_calendar_marks_half_days_pending_and_respects_scope(): void
    {
        [, $me] = $this->signIn('manager');
        [, $other] = $this->makeStaff('manager');
        $type = $this->leaveType();
        $mine = $this->person('Report Rita', [], ['manager_id' => $me->id]);
        $this->leaveFor($mine, $type, '2026-10-05', '2026-10-06');
        $half = $this->leaveFor($mine, $type, '2026-10-08', half: 'PM');
        app(LeaveService::class)->approve(User::query()->whereHas('roles', fn ($q) => $q->where('slug', 'hr'))->first(), $half);
        $this->leaveFor($this->person('Other Olga', [], ['manager_id' => $other->id]), $type, '2026-10-07');

        $response = $this->get('/admin/leave-requests?tab=calendar&month=2026-10')->assertOk();
        $rows = $response->viewData('rows');

        $this->assertSame(['Report Rita'], $rows->map(fn ($r) => $r['employee']->full_name)->all());
        $cells = $rows->first()['cells'];
        $this->assertTrue($cells['2026-10-05']['pending']);
        $this->assertSame('PM', $cells['2026-10-08']['half']);
        $this->assertFalse($cells['2026-10-08']['pending']);
        $response->assertSee('October 2026')->assertSee('Weekend / holiday');
        $this->get('/admin/leave-requests?tab=calendar&month=nope')->assertSessionHasErrors('month');
    }

    public function test_the_calendar_is_company_wide_for_hr(): void
    {
        $this->signIn('hr');
        $type = $this->leaveType();
        $this->leaveFor($this->person('One Ann'), $type);
        $this->leaveFor($this->person('Two Ben'), $type, '2026-10-06');

        $this->assertCount(2, $this->get('/admin/leave-requests?tab=calendar&month=2026-10')->viewData('rows'));
    }
}
