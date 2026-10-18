<?php

namespace Tests\Feature\Admin;

use App\Models\Activity;

class ReportWebTest extends Batch2TestCase
{
    public function test_catalogue_shows_only_reports_the_viewer_may_run(): void
    {
        $this->signIn('employee');
        $this->get(route('admin.reports'))->assertForbidden();

        $this->signIn('manager');
        $this->get(route('admin.reports'))->assertOk()->assertSee('Daily attendance')->assertSee('Leave usage')->assertDontSee('Payroll summary')->assertDontSee('Expiring documents');

        $this->actingAs($this->hr)->get(route('admin.reports'))->assertOk()->assertSee('Attendance')->assertSee('Leave')->assertSee('Payroll summary')->assertSee('Expiring documents');

        $payrollOnly = $this->customUser('payroll-reports', ['admin.access', 'report.view', 'payroll.view']);
        $this->actingAs($payrollOnly)->get(route('admin.reports'))->assertOk()->assertSee('Payroll summary')->assertSee('Payroll by employee')->assertDontSee('Daily attendance')->assertDontSee('Leave usage');
        $this->get(route('admin.reports.show', 'attendance.daily'))->assertNotFound();
        $this->get(route('admin.reports.export', ['attendance.daily', 'csv']))->assertNotFound();

        $noReport = $this->customUser('no-report', ['admin.access', 'attendance.view']);
        $this->actingAs($noReport)->get(route('admin.reports'))->assertForbidden();
    }

    public function test_forbidden_and_unknown_reports_look_the_same(): void
    {
        [$manager] = $this->signIn('manager');
        $this->get(route('admin.reports.show', 'payroll.summary'))->assertNotFound();
        $this->get(route('admin.reports.show', 'nothing.here'))->assertNotFound();
        $this->get('/admin/reports/..%2Fetc')->assertNotFound();
        $this->get(route('admin.reports.export', ['payroll.summary', 'csv']))->assertNotFound();
        $this->get('/admin/reports/attendance.daily/export/xml')->assertNotFound();
        $this->assertNotNull($manager);
    }

    public function test_attendance_report_runs_with_filters_rows_totals_and_tiles(): void
    {
        $a = $this->person('Late Larry');
        $b = $this->person('Prompt Pam');
        $this->present($a, ['2026-09-01', '2026-09-02']);
        $this->present($b, ['2026-09-01']);
        $this->actingAs($this->hr);

        $this->get(route('admin.reports.show', ['key' => 'attendance.daily', 'from' => '2026-09-01', 'to' => '2026-09-30']))
            ->assertOk()->assertSee('Daily attendance')->assertSee('Late Larry')->assertSee('Prompt Pam')->assertSee('Rows')->assertSee('3');
        $html = $this->get(route('admin.reports.show', ['key' => 'attendance.daily', 'from' => '2026-09-01', 'to' => '2026-09-30', 'employee_id' => $b->id]))->assertSee('Prompt Pam')->getContent();
        $this->assertSame(1, substr_count($html, 'Late Larry'), 'only the filter option, no table rows');
        $this->get(route('admin.reports.show', ['key' => 'attendance.daily', 'from' => '2026-01-01', 'to' => '2026-01-05']))->assertSee('No rows for these filters');

        $this->get(route('admin.reports.show', ['key' => 'attendance.daily', 'from' => '2026-09-30', 'to' => '2026-09-01']))->assertSessionHasErrors('to');
        $this->get(route('admin.reports.show', ['key' => 'attendance.daily', 'from' => '2020-01-01', 'to' => '2026-09-01']))->assertSessionHasErrors('to');
        $this->get(route('admin.reports.show', ['key' => 'attendance.daily', 'from' => 'nonsense']))->assertSessionHasErrors('from');
    }

    public function test_by_department_report_has_a_bar_summary(): void
    {
        $this->present($this->person('Dept Dan'), ['2026-09-01']);
        $this->actingAs($this->hr)->get(route('admin.reports.show', ['key' => 'attendance.by_department', 'from' => '2026-09-01', 'to' => '2026-09-30']))
            ->assertOk()->assertSee('Late arrivals by department')->assertSee('<svg', false);
    }

    public function test_csv_and_pdf_exports_use_the_same_filters_and_are_audited(): void
    {
        $a = $this->person('Csv Carl');
        $b = $this->person('Other Olga');
        $this->present($a, ['2026-09-01']);
        $this->present($b, ['2026-09-01']);
        $this->actingAs($this->hr);

        $csv = $this->get(route('admin.reports.export', ['key' => 'attendance.daily', 'format' => 'csv', 'from' => '2026-09-01', 'to' => '2026-09-30', 'employee_id' => $a->id]))->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('Content-Type'));
        $body = $csv->streamedContent();
        $this->assertStringContainsString('Csv Carl', $body);
        $this->assertStringNotContainsString('Other Olga', $body);
        $this->assertSame("\xEF\xBB\xBF", substr($body, 0, 3));

        $pdf = $this->get(route('admin.reports.export', ['key' => 'attendance.daily', 'format' => 'pdf', 'from' => '2026-09-01', 'to' => '2026-09-30']))->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));

        $this->assertSame(2, Activity::query()->where('action', 'report.exported')->count());
        $this->get(route('admin.reports.export', ['key' => 'attendance.daily', 'format' => 'csv', 'from' => '2026-09-30', 'to' => '2026-09-01']))->assertSessionHasErrors('to');
    }

    public function test_manager_reports_are_scoped_to_the_team(): void
    {
        [, $me] = $this->signIn('manager');
        $mine = $this->person('Team Tina', assignment: ['manager_id' => $me->id]);
        $other = $this->person('Stranger Sam');
        $this->present($mine, ['2026-09-01']);
        $this->present($other, ['2026-09-01']);

        $query = ['key' => 'attendance.daily', 'from' => '2026-09-01', 'to' => '2026-09-30'];
        $this->get(route('admin.reports.show', $query))->assertOk()->assertSee('Team Tina')->assertDontSee('Stranger Sam');
        $this->get(route('admin.reports.show', $query + ['employee_id' => $other->id]))->assertOk()->assertDontSee('Stranger Sam')->assertSee('No rows');
        $body = $this->get(route('admin.reports.export', $query + ['format' => 'csv']))->streamedContent();
        $this->assertStringContainsString('Team Tina', $body);
        $this->assertStringNotContainsString('Stranger Sam', $body);
        $html = $this->get(route('admin.reports.show', $query))->getContent();
        $this->assertStringNotContainsString('Stranger Sam', $html, 'employee filter must not list people outside the team');
    }

    public function test_payroll_reports_default_to_the_latest_generated_period(): void
    {
        $this->actingAs($this->hr);
        $this->get(route('admin.reports.show', 'payroll.detail'))->assertOk()->assertSee('No rows for these filters')->assertSee('Create and generate a payroll period first');

        $period = $this->finalizedPeriod('Pay Pal');
        $net = number_format((float) $period->run->total_net, 0, '.', ',');
        $this->get(route('admin.reports.show', 'payroll.detail'))->assertOk()->assertSee('Pay Pal')->assertSee($net)->assertSee('1 employees');
        $this->get(route('admin.reports.show', ['key' => 'payroll.summary', 'from' => '2026-07-01', 'to' => '2026-07-31']))->assertOk()->assertSee('July 2026')->assertSee('Finalized');
    }

    public function test_documents_reports_need_document_view(): void
    {
        $this->actingAs($this->hr)->get(route('admin.reports.show', 'documents.expiring'))->assertOk()->assertSee('Expiring documents');
        $payrollOnly = $this->customUser('p-only', ['admin.access', 'report.view', 'payroll.view']);
        $this->actingAs($payrollOnly)->get(route('admin.reports.show', 'documents.expiring'))->assertNotFound();
    }

    public function test_report_pages_escape_user_content(): void
    {
        $xss = '<script>alert(1)</script>';
        $this->present($this->person($xss), ['2026-09-01']);
        $this->actingAs($this->hr);
        $html = $this->get(route('admin.reports.show', ['key' => 'attendance.daily', 'from' => '2026-09-01', 'to' => '2026-09-30']))->assertOk()->getContent();
        $this->assertStringNotContainsString($xss, $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
