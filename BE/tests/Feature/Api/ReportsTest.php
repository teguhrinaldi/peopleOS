<?php

namespace Tests\Feature\Api;

use App\Enums\AttendanceStatus;
use App\Models\Activity;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Services\Attendance\ScheduleService;
use App\Services\Leave\LeaveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LeaveFixtures;
use Tests\Support\WorkplaceFixtures;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use LeaveFixtures, RefreshDatabase, WorkplaceFixtures;

    private const FROM = '2026-09-28'; // Monday

    private const TO = '2026-10-02';   // Friday

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkplace();
        $this->setUpLeave();
        $this->at('2026-10-05 10:00'); // Monday after the window
    }

    private function record(Employee $employee, string $date, string $in, string $out, bool $late = false, int $lateMinutes = 0): Attendance
    {
        $schedule = app(ScheduleService::class)->forDate($employee, $date);

        return Attendance::query()->create([
            'employee_id' => $employee->id, 'attendance_schedule_id' => $schedule->id, 'work_date' => $date, 'status' => AttendanceStatus::CheckedOut,
            'check_in_at' => "{$date} {$in}:00", 'check_out_at' => "{$date} {$out}:00", 'worked_minutes' => 480,
            'is_late' => $late, 'late_minutes' => $lateMinutes,
        ]);
    }

    private function url(string $key, array $query = []): string
    {
        return "/api/v1/reports/{$key}?".http_build_query(['from' => self::FROM, 'to' => self::TO] + $query);
    }

    public function test_catalogue_depends_on_permissions(): void
    {
        [$employee] = $this->makeStaff();
        [$manager] = $this->makeStaff('manager');
        [$hr] = $this->makeStaff('hr');

        $this->actingAsApi($employee)->getJson('/api/v1/reports')->assertOk()->assertJsonCount(0, 'data');

        $keys = collect($this->actingAsApi($manager)->getJson('/api/v1/reports')->json('data'))->pluck('key');
        $this->assertTrue($keys->contains('attendance.daily'));
        $this->assertTrue($keys->contains('leave.usage'));
        $this->assertFalse($keys->contains('payroll.summary'), 'managers do not get payroll');
        $this->assertFalse($keys->contains('documents.expiring'));

        $this->assertGreaterThanOrEqual(11, count($this->actingAsApi($hr)->getJson('/api/v1/reports')->json('data')));
    }

    public function test_a_forbidden_or_unknown_report_is_an_identical_404(): void
    {
        [$manager] = $this->makeStaff('manager');
        [$employee] = $this->makeStaff();

        $this->actingAsApi($manager)->getJson($this->url('payroll.summary'))->assertStatus(404);
        $this->getJson($this->url('does.not_exist'))->assertStatus(404);
        $this->actingAsApi($employee)->getJson($this->url('attendance.daily'))->assertStatus(404);
        $this->get('/api/v1/reports/payroll.summary/export/csv', ['Accept' => 'application/json'])->assertStatus(404);
    }

    public function test_daily_report_lists_real_rows_and_managers_only_see_their_team(): void
    {
        [$manager, $managerEmployee] = $this->makeStaff('manager');
        [, $report] = $this->makeStaff('employee', ['full_name' => 'Team Member'], ['manager_id' => $managerEmployee->id]);
        [, $stranger] = $this->makeStaff('employee', ['full_name' => 'Outsider']);
        $this->record($report, '2026-09-29', '01:00', '10:00');
        $this->record($stranger, '2026-09-29', '01:00', '10:00');

        $rows = $this->actingAsApi($manager)->getJson($this->url('attendance.daily'))->assertOk()->json('data.rows');
        $this->assertSame(['Team Member'], array_column($rows, 'employee'));
        $this->assertSame('08:00', $rows[0]['check_in']);   // 01:00 UTC shown in company time
        $this->assertSame('8:00', $rows[0]['worked']);

        [$hr] = $this->makeStaff('hr');
        $this->assertCount(2, $this->actingAsApi($hr)->getJson($this->url('attendance.daily'))->json('data.rows'));
        // A guessed employee_id cannot widen the manager's scope.
        $this->assertCount(0, $this->actingAsApi($manager)->getJson($this->url('attendance.daily', ['employee_id' => $stranger->id]))->json('data.rows'));
    }

    public function test_late_and_summary_and_department_reports_aggregate_correctly(): void
    {
        [$hr] = $this->makeStaff('hr');
        [, $a] = $this->makeStaff('employee', ['full_name' => 'Alpha']);
        [, $b] = $this->makeStaff('employee', ['full_name' => 'Bravo']);
        $this->record($a, '2026-09-28', '01:30', '10:00', true, 30);
        $this->record($a, '2026-09-29', '01:00', '10:00');
        $this->record($b, '2026-09-28', '01:45', '10:00', true, 45);
        $this->actingAsApi($hr);

        $late = $this->getJson($this->url('attendance.late'))->json('data');
        $this->assertSame([45, 30], array_column($late['rows'], 'late_minutes'), 'worst first');
        $this->assertSame(75, $late['totals']['late_minutes']);

        $byEmployee = collect($this->getJson($this->url('attendance.by_employee'))->json('data.rows'))->keyBy('employee');
        $this->assertSame(2, $byEmployee['Alpha']['days_present']);
        $this->assertSame(1, $byEmployee['Alpha']['late_days']);
        $this->assertSame('16:00', $byEmployee['Alpha']['worked']);

        $dept = $this->getJson($this->url('attendance.by_department'))->json('data.rows');
        $this->assertSame('Marketing', $dept[0]['department']);
        $this->assertSame(3, $dept[0]['check_ins']);
        $this->assertSame('66.7%', $dept[0]['late_rate']);
    }

    public function test_absent_report_excludes_present_days_leave_weekends_and_today(): void
    {
        [$hr] = $this->makeStaff('hr');
        [$user, $employee] = $this->makeStaff('employee', ['full_name' => 'Gamma']);
        $this->record($employee, '2026-09-28', '01:00', '10:00'); // Mon present
        // Tue approved leave
        $id = $this->actingAsApi($user)->postJson('/api/v1/leave-requests', $this->leavePayload($this->sick, '2026-09-29', null, ['reason' => 'Fever']))->assertCreated()->json('data.id');
        app(LeaveService::class)->approve($hr, LeaveRequest::query()->findOrFail($id));

        $this->actingAsApi($hr);
        $rows = $this->getJson($this->url('attendance.absent', ['to' => '2026-10-05']))->assertOk()->json('data.rows');
        $gamma = collect($rows)->where('employee', 'Gamma')->pluck('date')->all();

        $this->assertSame(['2026-09-30', '2026-10-01', '2026-10-02'], $gamma, 'Wed-Fri absent; Mon present, Tue on leave, weekend skipped, today (Mon 5th) not yet counted');
    }

    public function test_leave_reports_use_approved_days_and_statuses(): void
    {
        [$hr] = $this->makeStaff('hr');
        [$user, $employee] = $this->makeStaff('employee', ['full_name' => 'Delta']);
        LeavePolicy::query()->update(['max_past_days' => 14, 'min_notice_days' => 0]); // the fixture window is in the past
        $approved = $this->actingAsApi($user)->postJson('/api/v1/leave-requests', $this->leavePayload($this->unpaid, '2026-09-29', '2026-09-30'))->json('data.id');
        $this->actingAsApi($user)->postJson('/api/v1/leave-requests', $this->leavePayload($this->annual, '2026-10-01'))->assertCreated();
        app(LeaveService::class)->approve($hr, LeaveRequest::query()->findOrFail($approved));

        $this->actingAsApi($hr);
        $usage = $this->getJson($this->url('leave.usage'))->json('data');
        $this->assertCount(1, $usage['rows']);
        $this->assertSame('2.0', $usage['rows'][0]['days']);
        $this->assertSame('Unpaid', $usage['rows'][0]['paid']);

        $pending = $this->getJson($this->url('leave.requests', ['status' => 'pending']))->json('data.rows');
        $this->assertCount(1, $pending);
        $this->assertSame('PENDING', $pending[0]['status']);
        $this->assertSame(2, count($this->getJson($this->url('leave.requests'))->json('data.rows')));
        $this->assertSame($employee->full_name, $pending[0]['employee']);
    }

    public function test_csv_export_streams_all_rows_neutralizes_formulas_and_is_audited(): void
    {
        [$hr] = $this->makeStaff('hr');
        [, $employee] = $this->makeStaff('employee', ['full_name' => '=HYPERLINK("http://evil.example","x")']);
        $this->record($employee, '2026-09-29', '01:00', '10:00');

        $response = $this->actingAsApi($hr)->get('/api/v1/reports/attendance.daily/export/csv?from='.self::FROM.'&to='.self::TO);
        $response->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('"Employee no.",Employee', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv, 'formula cells are prefixed so spreadsheets treat them as text');
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
        $this->assertTrue(Activity::query()->where('action', 'report.exported')->where('actor_id', $hr->id)->exists());
    }

    public function test_pdf_export_is_a_real_pdf(): void
    {
        [$hr] = $this->makeStaff('hr');
        [, $employee] = $this->makeStaff();
        $this->record($employee, '2026-09-29', '01:00', '10:00');

        $response = $this->actingAsApi($hr)->get('/api/v1/reports/attendance.daily/export/pdf?from='.self::FROM.'&to='.self::TO);
        $response->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->get('/api/v1/reports/attendance.daily/export/xlsx', ['Accept' => 'application/json'])->assertStatus(404);
    }

    public function test_invalid_ranges_are_rejected(): void
    {
        [$hr] = $this->makeStaff('hr');
        $this->actingAsApi($hr);

        $this->getJson('/api/v1/reports/attendance.daily?from=2026-10-05&to=2026-10-01')->assertStatus(422)->assertJsonPath('error.code', 'INVALID_DATE_RANGE');
        $this->getJson('/api/v1/reports/attendance.daily?from=2024-01-01&to=2026-10-01')->assertStatus(422)->assertJsonPath('error.code', 'DATE_RANGE_TOO_LONG');
        $this->getJson('/api/v1/reports/attendance.daily?from=nonsense')->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_responses_are_paginated_but_exports_are_not(): void
    {
        [$hr] = $this->makeStaff('hr');
        [, $employee] = $this->makeStaff();
        foreach (['2026-09-28', '2026-09-29', '2026-09-30'] as $day) {
            $this->record($employee, $day, '01:00', '10:00');
        }
        $this->actingAsApi($hr);

        $page = $this->getJson($this->url('attendance.daily', ['per_page' => 2]))->assertOk();
        $page->assertJsonCount(2, 'data.rows')->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 2);
        $csv = $this->get('/api/v1/reports/attendance.daily/export/csv?from='.self::FROM.'&to='.self::TO)->streamedContent();
        $this->assertSame(4, substr_count(trim($csv), "\n") + 1, 'header + 3 rows');
    }
}
