<?php

namespace Tests\Feature\Admin;

use App\Models\Employee;
use App\Services\Admin\AttendanceMetrics;
use App\Services\Admin\DashboardService;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * The one definition of On time / Late / Absent / Expected (AttendanceMetrics) and the dashboard pieces that use it.
 * Clock: Wednesday 2026-09-30 10:00 Asia/Jakarta (AdminTestCase). Sun 27 Sep is a rest day in the default calendar.
 */
class AttendanceMetricsTest extends AdminTestCase
{
    private function everyone(): Builder
    {
        return Employee::query()->where('company_id', $this->company->id);
    }

    /** @return array<string, array<string, mixed>> keyed by date */
    private function series(string $from = '2026-09-27', string $to = '2026-09-30'): array
    {
        return collect(app(AttendanceMetrics::class)->series($this->everyone(), $from, $to))->keyBy('date')->all();
    }

    public function test_definitions_against_a_known_fixture(): void
    {
        $onTime = $this->person('On Time Olga');
        $late = $this->person('Late Larry');
        $absent = $this->person('Absent Abe');
        $leave = $this->person('Leave Lena');
        $this->attendance($onTime, '2026-09-29');
        $this->attendance($late, '2026-09-29', 25);
        $this->leaveFor($leave, $this->leaveType(policy: ['max_past_days' => 30]), '2026-09-29');
        \DB::table('leave_requests')->where('employee_id', $leave->id)->update(['status' => 'APPROVED']);
        $this->attendance($onTime, '2026-09-30');
        $this->attendance($late, '2026-09-28', 40, 'REJECTED'); // rejected rows never count as present

        $s = $this->series();

        // Tuesday (closed working day): on time + late = present; absent = expected without check-in; full-day leave is not expected.
        $this->assertSame(['expected' => 4, 'present' => 2, 'ontime' => 1, 'late' => 1, 'absent' => 2, 'notYet' => null],
            array_intersect_key($s['2026-09-29'], array_flip(['expected', 'present', 'ontime', 'late', 'absent', 'notYet'])));
        $this->assertSame($s['2026-09-29']['ontime'] + $s['2026-09-29']['late'], $s['2026-09-29']['present']);

        // Monday: a working day where nobody valid checked in is a real zero (all expected are absent).
        $this->assertSame(0, $s['2026-09-28']['present']);
        $this->assertSame(5, $s['2026-09-28']['absent']); // incl. the HR person who filed the leave (also has a shift)

        // Sunday: rest day -> no data, never "4 absent".
        $this->assertFalse($s['2026-09-27']['working']);
        $this->assertSame(0, $s['2026-09-27']['expected']);
        $this->assertNull($s['2026-09-27']['absent']);

        // Today: no absences yet, only people not checked in so far.
        $this->assertTrue($s['2026-09-30']['today']);
        $this->assertNull($s['2026-09-30']['absent']);
        $this->assertSame(5, $s['2026-09-30']['expected']);
        $this->assertSame(1, $s['2026-09-30']['present']);
        $this->assertSame(4, $s['2026-09-30']['notYet']);

        $totals = app(AttendanceMetrics::class)->totals(array_values($s));
        $this->assertSame(['present' => 3, 'ontime' => 2, 'late' => 1, 'absent' => 7, 'workingDays' => 3], $totals);
    }

    public function test_join_and_termination_dates_and_inactive_people_limit_who_is_expected(): void
    {
        $this->person('New Nina', ['join_date' => '2026-09-29']);
        $this->person('Gone Gus', ['status' => 'TERMINATED', 'terminated_at' => '2026-09-28']);
        $this->person('Paused Pia', ['status' => 'INACTIVE']);
        $this->person('No Shift Sam', [], ['shift_id' => null]);

        $s = $this->series('2026-09-28', '2026-09-29');
        $this->assertSame(0, $s['2026-09-28']['expected']);
        $this->assertSame(1, $s['2026-09-29']['expected']);
    }

    public function test_empty_and_reversed_ranges_and_future_days(): void
    {
        $this->assertSame([], app(AttendanceMetrics::class)->series($this->everyone(), '2026-10-05', '2026-10-01'));
        $s = app(AttendanceMetrics::class)->series($this->everyone(), '2026-09-29', '2026-10-03');
        $this->assertSame('2026-09-30', end($s)['date'], 'days after today are never invented');
        $this->assertCount(2, $s);
    }

    public function test_company_timezone_decides_the_business_day_of_a_real_api_check_in(): void
    {
        [$early] = $this->makeStaff('employee');
        [$late] = $this->makeStaff('employee');

        // 06:40 Asia/Jakarta = 23:40 UTC on the PREVIOUS UTC date.
        $this->at('2026-09-30 06:40');
        $this->actingAsApi($early)->postJson('/api/v1/attendance/check-in', $this->capture('p3-early-0001'))->assertCreated();
        $this->at('2026-09-30 08:40');
        $this->actingAsApi($late)->postJson('/api/v1/attendance/check-in', $this->capture('p3-late-00001'))->assertCreated()
            ->assertJsonPath('data.attendance.is_late', true);
        $this->at('2026-09-30 10:00');

        $s = $this->series('2026-09-29', '2026-09-30');
        $this->assertSame(0, $s['2026-09-29']['present'], 'the 23:40 UTC check-in is not on the UTC date');
        $this->assertSame(['present' => 2, 'ontime' => 1, 'late' => 1], array_intersect_key($s['2026-09-30'], array_flip(['present', 'ontime', 'late'])));
    }

    public function test_kpi_cards_and_chart_use_the_same_numbers_and_html_matches(): void
    {
        [, $me] = $this->signIn('hr');
        $a = $this->person('Alpha');
        $b = $this->person('Bravo');
        $this->attendance($a, '2026-09-29', 30);
        $this->attendance($b, '2026-09-29');
        $this->attendance($a, '2026-09-30', 30);

        $d = app(DashboardService::class)->forUser(auth()->user());
        $today = end($d['trend']);
        $this->assertSame([$d['kpis']['present'], $d['kpis']['late'], $d['kpis']['expected'], $d['kpis']['notYet']], [$today['present'], $today['late'], $today['expected'], $today['notYet']]);
        $this->assertSame(3, $today['expected']); // Alpha, Bravo and the signed-in HR person all have a shift
        $this->assertNotNull($me);

        $html = $this->get(route('admin.dashboard.trend', ['days' => 7]))->assertOk()->getContent();
        $this->assertStringContainsString('Tue 29 Sep: 2 present (1 on time, 1 late), 1 absent', $html);
        $this->assertStringContainsString('Wed 30 Sep: 1 present (0 on time, 1 late), 2 not checked in yet (today, still in progress)', $html);
        $this->assertStringContainsString('Sun 27 Sep: rest day or holiday, no attendance expected', $html);
        $this->assertStringContainsString('24 Sep 2026 – 30 Sep 2026', $html);

        $this->get(route('admin.dashboard'))->assertOk()->assertSee('Attendance trend')->assertSee('Tue 29 Sep: 2 present (1 on time, 1 late), 1 absent');
        $this->get(route('admin.dashboard.trend', ['days' => 30]))->assertOk()->assertSee('01 Sep 2026 – 30 Sep 2026');
        $this->get(route('admin.dashboard.trend', ['days' => 5]))->assertSessionHasErrors('days');
    }

    public function test_managers_only_see_their_team(): void
    {
        [, $me] = $this->signIn('manager');
        $mine = $this->person('Team Tia', [], ['manager_id' => $me->id]);
        $other = $this->person('Other Otto');
        $this->attendance($mine, '2026-09-29', 30);
        $this->attendance($other, '2026-09-29');

        $html = $this->get(route('admin.dashboard.trend'))->assertOk()->getContent();
        $this->assertStringContainsString('Tue 29 Sep: 1 present (0 on time, 1 late), 0 absent', $html);
        $d = app(DashboardService::class)->trend(auth()->user());
        $this->assertSame(1, $d['days'][5]['expected']);
    }

    public function test_an_empty_scope_shows_the_empty_state_not_zero_bars(): void
    {
        $this->signIn('manager'); // nobody reports to this manager
        $this->get(route('admin.dashboard.trend'))->assertOk()->assertSee('No attendance data for')->assertDontSee('<svg viewBox="0 0 640', false);
    }

    public function test_a_failing_aggregation_shows_an_error_state_instead_of_fake_numbers(): void
    {
        $this->signIn('hr');
        $this->mock(AttendanceMetrics::class, function ($m) {
            $m->shouldReceive('series')->andThrow(new RuntimeException('db down'));
            $m->shouldReceive('totals')->andReturn(['present' => 0, 'ontime' => 0, 'late' => 0, 'absent' => 0, 'workingDays' => 0]);
        });

        $this->get(route('admin.dashboard.trend'))->assertOk()->assertSee('The attendance trend could not be loaded')->assertSee('Try again');
        $this->get(route('admin.dashboard'))->assertOk()->assertSee('The attendance trend could not be loaded');
    }
}
