<?php

namespace Tests\Feature\Admin;

use App\Models\Activity;
use App\Models\Payslip;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PayslipWebTest extends Batch2TestCase
{
    private function slip(): Payslip
    {
        $period = $this->finalizedPeriod();

        return Payslip::query()->where('payroll_period_id', $period->id)->firstOrFail();
    }

    public function test_permissions_and_direct_access(): void
    {
        $slip = $this->slip();

        $this->signIn('employee');
        $this->get(route('admin.payslips'))->assertForbidden();
        $this->get(route('admin.payslips.show', $slip))->assertForbidden();
        $this->get(route('admin.payslips.pdf', $slip))->assertForbidden();
        $this->post(route('admin.payslips.regenerate', $slip))->assertForbidden();

        $this->signIn('manager');
        $this->get(route('admin.payslips'))->assertForbidden();
        $this->get(route('admin.payslips.pdf', $slip))->assertForbidden();

        // own-payslip permission alone does not open the admin list or other people's slips.
        $own = $this->customUser('own-slips', ['admin.access', 'payslip.view_own']);
        $this->actingAs($own);
        $this->get(route('admin.payslips'))->assertForbidden();
        $this->get(route('admin.payslips.pdf', $slip))->assertForbidden();

        $viewer = $this->customUser('slip-viewer', ['admin.access', 'payslip.view']);
        $this->actingAs($viewer);
        $this->get(route('admin.payslips'))->assertOk();
        $this->post(route('admin.payslips.regenerate', $slip))->assertForbidden();
    }

    public function test_unknown_payslip_ids_answer_404(): void
    {
        $this->actingAs($this->hr);
        foreach (['', '/pdf'] as $suffix) {
            $this->get('/admin/payslips/987654'.$suffix)->assertNotFound();
        }
        $this->post('/admin/payslips/987654/regenerate')->assertNotFound();
    }

    public function test_list_search_and_filters(): void
    {
        $slip = $this->slip();
        $this->actingAs($this->hr);

        $this->get(route('admin.payslips'))->assertOk()->assertSee($slip->payslip_number)->assertSee('Paula Payroll')->assertSee('IDR')->assertSee('Ready');
        $this->get(route('admin.payslips', ['q' => 'paula']))->assertSee($slip->payslip_number);
        $this->get(route('admin.payslips', ['q' => $slip->payslip_number]))->assertSee('Paula Payroll');
        $this->get(route('admin.payslips', ['q' => 'nobody-here']))->assertSee('No payslips match');
        $this->get(route('admin.payslips', ['period_id' => $slip->payroll_period_id, 'year' => 2026]))->assertSee($slip->payslip_number);
        $this->get(route('admin.payslips', ['year' => 2024]))->assertSee('No payslips match');
        $this->get(route('admin.payslips', ['q' => str_repeat('x', 200)]))->assertSessionHasErrors('q');
    }

    public function test_detail_and_pdf_stream_from_the_private_disk(): void
    {
        $slip = $this->slip();
        $this->actingAs($this->hr);

        $this->get(route('admin.payslips.show', $slip))->assertOk()->assertSee('Payslip '.$slip->payslip_number)->assertSee('Basic Salary')->assertSee('Net pay')
            ->assertDontSee($slip->getRawOriginal('file_path'));

        $response = $this->get(route('admin.payslips.pdf', $slip))->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('%PDF', $response->streamedContent());

        $this->get(route('admin.payslips.pdf', [$slip, 'download' => 1]))->assertOk()->assertHeader('Content-Disposition');
        $this->assertStringContainsString('attachment', (string) $this->get(route('admin.payslips.pdf', [$slip, 'download' => 1]))->headers->get('Content-Disposition'));
    }

    public function test_missing_pdf_is_regenerated_and_audited(): void
    {
        $slip = $this->slip();
        $path = $slip->getRawOriginal('file_path');
        Storage::disk('local')->delete($path);
        DB::table('payslips')->where('id', $slip->id)->update(['file_path' => null]);
        $this->assertFalse($slip->fresh()->hasFile());

        $this->actingAs($this->hr)->get(route('admin.payslips.show', $slip))->assertSee('The PDF file is missing')->assertSee('Generate missing PDF');
        $this->post(route('admin.payslips.regenerate', $slip))->assertRedirect()->assertSessionHas('success');
        $this->assertTrue($slip->fresh()->hasFile());
        $this->assertTrue(Activity::query()->where('action', 'payslip.pdf_regenerated')->exists());

        // Idempotent: a second call writes nothing and says so.
        $this->post(route('admin.payslips.regenerate', $slip))->assertRedirect()->assertSessionHas('success');
        $this->assertSame(1, Activity::query()->where('action', 'payslip.pdf_regenerated')->count());
    }

    public function test_employee_tab_lists_the_employees_payslips(): void
    {
        $slip = $this->slip();
        $employee = $slip->employee;
        $this->actingAs($this->hr)->get(route('admin.employees.show', [$employee, 'tab' => 'payroll']))->assertOk()->assertSee($slip->payslip_number);

        $viewer = $this->customUser('only-slips', ['admin.access', 'employee.view', 'payslip.view']);
        $this->actingAs($viewer)->get(route('admin.employees.show', [$employee, 'tab' => 'payroll']))->assertOk()->assertSee($slip->payslip_number)->assertDontSee('Current salary');
    }
}
