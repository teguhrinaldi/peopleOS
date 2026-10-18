<?php

namespace Tests\Feature\Admin;

use App\Services\Admin\DashboardService;
use App\Services\Leave\LeaveService;
use Illuminate\Support\Facades\DB;

class DashboardLeaveTest extends AdminTestCase
{
    public function test_on_leave_today_counts_people_with_approved_leave_covering_today_only(): void
    {
        [$hr] = $this->makeStaff('hr');
        $type = $this->leaveType();
        $service = app(LeaveService::class);

        $full = $this->person('Full Fred');
        $half = $this->person('Half Hana');
        $pending = $this->person('Pending Pia');
        $past = $this->person('Past Pat');
        $future = $this->person('Future Fay');
        $service->approve($hr, $this->leaveFor($full, $type, '2026-09-30'));
        $service->approve($hr, $this->leaveFor($half, $type, '2026-09-30', half: 'AM'));
        $this->leaveFor($pending, $type, '2026-09-30');
        $service->approve($hr, $this->leaveFor($future, $type, '2026-10-05'));
        // A request that already ended is created through the service too, then moved into the past by the clock.
        $this->at('2026-09-23 10:00');
        $service->approve($hr, $this->leaveFor($past, $type, '2026-09-24'));
        $this->at('2026-09-30 10:00');

        $kpis = app(DashboardService::class)->forUser($hr)['kpis'];

        $this->assertSame(2, $kpis['onLeave'], 'full-day and half-day approved leave; pending, past and future do not count');
        $this->assertSame(1, $kpis['onLeaveHalf']);
    }

    public function test_people_on_full_day_leave_are_not_expected_to_check_in(): void
    {
        [$hr] = $this->makeStaff('hr');
        $type = $this->leaveType();
        $this->person('Working Wes');
        $off = $this->person('Off Oz');

        $before = app(DashboardService::class)->forUser($hr)['kpis'];
        app(LeaveService::class)->approve($hr, $this->leaveFor($off, $type, '2026-09-30'));
        $after = app(DashboardService::class)->forUser($hr)['kpis'];

        $this->assertSame($before['expected'] - 1, $after['expected']);
        $this->assertSame($before['notYet'] - 1, $after['notYet']);
        $this->assertSame(1, $after['onLeave']);
    }

    public function test_the_pending_leave_card_is_scoped_and_excludes_own_requests(): void
    {
        [$manager, $me] = $this->makeStaff('manager');
        [, $other] = $this->makeStaff('manager');
        $type = $this->leaveType();
        $this->leaveFor($this->person('Report Rita', [], ['manager_id' => $me->id]), $type);
        $this->leaveFor($this->person('Other Olga', [], ['manager_id' => $other->id]), $type, '2026-10-06');
        $this->leaveFor($me, $type, '2026-10-07');
        [$hr] = $this->makeStaff('hr');

        $count = fn ($user) => collect(app(DashboardService::class)->forUser($user)['approvals'])->firstWhere('label', 'Leave requests')['count'];

        $this->assertSame(1, $count($manager));
        $this->assertSame(3, $count($hr));
    }

    public function test_managers_see_team_leave_and_the_page_links_to_the_calendar(): void
    {
        [$manager, $me] = $this->signIn('manager');
        [, $other] = $this->makeStaff('manager');
        $type = $this->leaveType();
        $this->leaveFor($this->person('Report Rita', [], ['manager_id' => $me->id]), $type, '2026-10-02');
        $this->leaveFor($this->person('Other Olga', [], ['manager_id' => $other->id]), $type, '2026-10-02');

        $upcoming = app(DashboardService::class)->forUser($manager)['upcomingLeave'];
        $this->assertSame(['Report Rita'], $upcoming->map(fn ($l) => $l->employee->full_name)->all());

        $this->get('/admin')->assertOk()->assertSee('Team leave')->assertSee('Report Rita')->assertDontSee('Other Olga')
            ->assertSee(route('admin.leave-requests', ['tab' => 'calendar']), false);
    }

    public function test_the_dashboard_has_no_leave_numbers_for_users_without_leave_permissions_and_shows_real_payroll_and_document_tiles(): void
    {
        $this->signIn('hr');
        $this->get('/admin')->assertOk()->assertSee('Payroll status')->assertSee('Expiring documents')->assertDontSee('Coming soon');

        [$employee] = $this->makeStaff('employee');
        $this->assertNull(app(DashboardService::class)->forUser($employee)['upcomingLeave']);
    }

    public function test_leave_queries_do_not_grow_with_the_number_of_people_on_leave(): void
    {
        [$hr] = $this->signIn('hr');
        $type = $this->leaveType();
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/admin')->assertOk();

            return count(DB::getQueryLog());
        };

        $this->leaveFor($this->person('Seed Sam'), $type, '2026-10-01');
        $small = $count();
        foreach (range(1, 8) as $i) {
            app(LeaveService::class)->approve($hr, $this->leaveFor($this->person("Off {$i}"), $type, '2026-09-30'));
        }

        $this->assertLessThanOrEqual($small, $count());
    }
}
