<?php

namespace Tests\Feature\Admin;

use App\Models\Activity;
use App\Models\AttendanceCorrection;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Admin\DashboardService;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class DashboardTest extends AdminTestCase
{
    /**
     * Known fixture, Wednesday 30 Sep 2026 10:00 Jakarta:
     *   8 active (with shift), 1 pending, 1 inactive, 1 terminated  -> total 10, active 8
     *   today: 3 checked in (1 late, 1 needs review), 1 REJECTED (ignored); yesterday: 2 checked in
     */
    private function seedFixture(): array
    {
        $active = collect(range(1, 8))->map(fn ($i) => $this->person("Active {$i}"));
        $this->person('Pending Pia', ['status' => 'PENDING']);
        $this->person('Inactive Ian', ['status' => 'INACTIVE']);
        $this->person('Gone Gus', ['status' => 'TERMINATED', 'terminated_at' => '2026-06-30']);

        $this->attendance($active[0], '2026-09-30', late: 0);
        $this->attendance($active[1], '2026-09-30', late: 25);
        $this->attendance($active[2], '2026-09-30', late: 5, review: true);
        $this->attendance($active[3], '2026-09-30', late: 0, status: 'REJECTED');
        $this->attendance($active[0], '2026-09-29', late: 0);
        $this->attendance($active[1], '2026-09-29', late: 30);

        return [$active];
    }

    public function test_kpis_match_the_known_fixture(): void
    {
        $this->seedFixture();
        [$hr] = $this->makeStaff('hr');

        $d = app(DashboardService::class)->forUser($hr);
        $k = $d['kpis'];

        // makeStaff added an active HR employee (with shift) to the 8 fixture people.
        $this->assertSame(11, $k['total']);
        $this->assertSame(9, $k['active']);
        $this->assertSame(1, $k['pending']);
        $this->assertSame(3, $k['present'], 'rejected check-in is not presence');
        $this->assertSame(1, $k['late']);
        $this->assertSame(9, $k['expected']);
        $this->assertSame(6, $k['notYet']);
        $this->assertSame(33.3, $k['presentRate']);
        $this->assertSame(33.3, $k['lateRate']);
        $this->assertTrue($d['workingDay']);
        $this->assertSame('Good morning, Person', $d['greeting']);
    }

    public function test_donut_and_weekly_trend_come_from_real_rows(): void
    {
        $this->seedFixture();
        [$hr] = $this->makeStaff('hr');

        $d = app(DashboardService::class)->forUser($hr);

        $this->assertSame([2, 1, 6, 0], array_column($d['donut'], 'value'), 'on time, late, not checked in, on leave');
        $this->assertCount(7, $d['trend']);
        $this->assertSame('Wed', $d['trend'][6]['label']);
        $this->assertTrue($d['trend'][6]['today']);
        $this->assertSame(['ontime' => 2, 'late' => 1], ['ontime' => $d['trend'][6]['ontime'], 'late' => $d['trend'][6]['late']]);
        $this->assertSame(['ontime' => 1, 'late' => 1], ['ontime' => $d['trend'][5]['ontime'], 'late' => $d['trend'][5]['late']]);
        $this->assertSame(0, $d['trend'][0]['ontime']);
    }

    public function test_the_company_timezone_decides_which_day_is_today(): void
    {
        $this->company->update(['timezone' => 'Pacific/Auckland']);
        app(CompanyContext::class)->forget();
        // 20:00 UTC on the 29th is already 09:00 on the 30th in Auckland (UTC+13 in NZ daylight time).
        $this->travelTo(CarbonImmutable::parse('2026-09-29 20:00:00', 'UTC'));

        $employee = $this->person('Kiwi Kai');
        $this->attendance($employee, '2026-09-30');
        [$hr] = $this->makeStaff('hr');

        $d = app(DashboardService::class)->forUser($hr);

        $this->assertSame('2026-09-30', $d['date']->toDateString());
        $this->assertSame(1, $d['kpis']['present']);
        $this->assertSame('Good morning, Person', $d['greeting']);
    }

    public function test_rest_days_and_holidays_expect_nobody(): void
    {
        $this->seedFixture();
        [$hr] = $this->makeStaff('hr');

        $this->at('2026-10-03 10:00'); // Saturday
        app(CompanyContext::class)->forget();
        $saturday = app(DashboardService::class)->forUser($hr);
        $this->assertFalse($saturday['workingDay']);
        $this->assertSame(0, $saturday['kpis']['expected']);
        $this->assertSame(0, $saturday['kpis']['notYet']);

        $this->at('2026-10-05 10:00'); // Monday declared a holiday
        Holiday::query()->create(['company_id' => $this->company->id, 'date' => '2026-10-05', 'name' => 'Company day', 'type' => 'company']);
        $holiday = app(DashboardService::class)->forUser($hr);
        $this->assertFalse($holiday['workingDay']);
        $this->assertSame(0, $holiday['kpis']['notYet']);
    }

    public function test_managers_see_only_their_team(): void
    {
        [$manager, $me] = $this->makeStaff('manager');
        $mine = collect([$this->person('Team A', [], ['manager_id' => $me->id]), $this->person('Team B', [], ['manager_id' => $me->id])]);
        $other = $this->person('Elsewhere Ed');
        $this->attendance($mine[0], '2026-09-30');
        $this->attendance($mine[1], '2026-09-30', late: 40);
        $this->attendance($other, '2026-09-30');

        $d = app(DashboardService::class)->forUser($manager);

        $this->assertSame('team', $d['scope']);
        $this->assertSame(2, $d['kpis']['total']);
        $this->assertSame(2, $d['kpis']['present']);
        $this->assertSame(1, $d['kpis']['late']);
        $this->assertSame(0, $d['kpis']['notYet']);
        $this->assertNull($d['activity'], 'managers have no audit-trail permission');
    }

    public function test_pending_approvals_are_scoped_and_only_shown_for_queues_the_user_may_act_on(): void
    {
        [$manager, $me] = $this->makeStaff('manager');
        $mine = $this->person('Team A', [], ['manager_id' => $me->id]);
        $other = $this->person('Elsewhere Ed');

        foreach ([$mine, $other] as $employee) {
            $att = $this->attendance($employee, '2026-09-29');
            AttendanceCorrection::query()->create([
                'employee_id' => $employee->id, 'attendance_id' => $att->id, 'attendance_schedule_id' => $att->attendance_schedule_id,
                'work_date' => '2026-09-29', 'reason' => 'Forgot', 'status' => 'PENDING',
            ]);
            OvertimeRequest::query()->create([
                'employee_id' => $employee->id, 'work_date' => '2026-09-29', 'start_at' => '2026-09-29 10:00:00', 'end_at' => '2026-09-29 12:00:00',
                'requested_minutes' => 120, 'reason' => 'Launch', 'status' => 'PENDING',
            ]);
        }
        $this->attendance($other, '2026-09-28', review: true);

        $service = app(DashboardService::class);
        $counts = fn (User $u) => collect($service->forUser($u)['approvals'])->pluck('count', 'label')->all();

        $this->assertSame(['Leave requests' => 0, 'Attendance corrections' => 1, 'Overtime requests' => 1], $counts($manager));

        [$hr] = $this->makeStaff('hr');
        $this->assertSame(['Leave requests' => 0, 'Attendance corrections' => 2, 'Overtime requests' => 2, 'Flagged attendance' => 1], $counts($hr));
    }

    public function test_recent_activity_lists_real_audit_rows_with_readable_sentences(): void
    {
        [$hr] = $this->signIn('hr');
        $employee = $this->person('Subject Sue', ['employee_number' => 'EMP-8001']);
        app(ActivityLogger::class)->log('employee.terminated', $employee, ['status' => 'ACTIVE'], ['status' => 'TERMINATED'], actor: $hr);

        $d = app(DashboardService::class)->forUser($hr);
        $first = $d['activity']->first();

        $this->assertSame($hr->name, $first['actor']);
        $this->assertSame('terminated', $first['verb']);
        $this->assertSame('Subject Sue (EMP-8001)', $first['subject']);

        $this->get('/admin')->assertOk()->assertSee('Subject Sue (EMP-8001)')->assertSee('terminated');
        $this->assertGreaterThan(0, Activity::query()->count());
    }

    public function test_dashboard_query_count_stays_flat_as_data_grows(): void
    {
        $this->signIn('hr');
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/admin')->assertOk();

            return count(DB::getQueryLog());
        };

        // One audit row up front so the feed already resolves its subject names in the baseline.
        app(ActivityLogger::class)->log('employee.updated', $this->person('Baseline Bo'), ['a' => 1], ['a' => 2]);

        $small = $count();
        foreach (range(1, 15) as $i) {
            $employee = $this->person("Growth {$i}");
            $this->attendance($employee, '2026-09-30');
            app(ActivityLogger::class)->log('employee.updated', $employee, ['a' => 1], ['a' => 2]);
        }
        $large = $count();

        // The second request is a little cheaper (warm singletons), so "flat" means "never more than before".
        $this->assertLessThanOrEqual($small, $large, "Dashboard queries grew from {$small} to {$large}");
        $this->assertLessThan(30, $large);
        $this->assertSame(17, Employee::query()->count());
    }
}
