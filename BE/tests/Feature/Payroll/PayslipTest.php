<?php

namespace Tests\Feature\Payroll;

use App\Models\AppNotification;
use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\Payslip;
use App\Models\User;
use App\Services\Payroll\PayslipDocuments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PayrollFixtures;
use Tests\Support\WorkplaceFixtures;
use Tests\TestCase;

class PayslipTest extends TestCase
{
    use PayrollFixtures, RefreshDatabase, WorkplaceFixtures;

    private User $alice;

    private User $bob;

    private Employee $aliceEmployee;

    private Employee $bobEmployee;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpWorkplace();
        $this->setUpPayroll();
        $this->at('2026-08-05 10:00');
        [$this->alice, $this->aliceEmployee] = $this->makeStaff();
        [$this->bob, $this->bobEmployee] = $this->makeStaff();
        $this->giveSalary($this->aliceEmployee, '8000000');
        $this->giveSalary($this->bobEmployee, '9000000');
    }

    private function slipOf(Employee $e): Payslip
    {
        return Payslip::query()->where('employee_id', $e->id)->firstOrFail();
    }

    public function test_finalize_issues_numbered_pdf_payslips_on_the_private_disk(): void
    {
        $this->toFinalized($this->period());

        $slips = Payslip::query()->orderBy('sequence')->get();
        $this->assertCount(2, $slips); // HR and Admin have no salary -> skipped (reported in the summary)
        $this->assertSame(['PS-202607-0001', 'PS-202607-0002'], $slips->pluck('payslip_number')->all());
        foreach ($slips as $slip) {
            $this->assertNotNull($slip->file_path);
            $this->assertStringStartsWith('payslips/2026/', $slip->file_path);
            Storage::disk('local')->assertExists($slip->file_path);
            $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($slip->file_path));
            $this->assertSame(hash('sha256', Storage::disk('local')->get($slip->file_path)), $slip->file_sha256);
        }
    }

    public function test_employee_sees_and_downloads_only_their_own_released_payslips(): void
    {
        $this->toFinalized($this->period());
        $mine = $this->slipOf($this->aliceEmployee);
        $theirs = $this->slipOf($this->bobEmployee);

        $this->actingAsApi($this->alice)->getJson('/api/v1/payslips')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('data.0.net_pay', '7900000.00')->assertJsonPath('data.0.currency', 'IDR')
            ->assertJsonMissingPath('data.0.file_path');

        $detail = $this->getJson("/api/v1/payslips/{$mine->id}")->assertOk()
            ->assertJsonPath('data.payslip_number', $mine->payslip_number)
            ->assertJsonPath('data.item.earnings.0.code', 'BASIC')
            ->assertJsonPath('data.item.deductions.0.code', 'COOP')
            ->assertJsonPath('data.company.name', 'Test Co');
        $this->assertStringNotContainsString('payslips/', $detail->getContent(), 'storage path never exposed');

        $pdf = $this->get("/api/v1/payslips/{$mine->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('no-store', $pdf->headers->get('Cache-Control'));
        $this->assertStringStartsWith('%PDF', $pdf->streamedContent());

        // IDOR: Bob's slip by id -> 404 on list filter, show and download
        $this->getJson('/api/v1/payslips?scope=all')->assertForbidden();
        $this->getJson("/api/v1/payslips?employee_id={$this->bobEmployee->id}")->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $mine->id);
        $this->getJson("/api/v1/payslips/{$theirs->id}")->assertNotFound();
        $this->getJson("/api/v1/payslips/{$theirs->id}/pdf")->assertNotFound();
        $this->getJson('/api/v1/payslips/999999')->assertNotFound();
    }

    public function test_employee_cannot_see_a_slip_of_payroll_that_is_not_finalized(): void
    {
        $period = $this->toFinalized($this->period());
        // simulate a slip on a non-released run (should never exist; policy must still refuse it)
        $august = $this->toReview($this->period('2026-08-01', '2026-08-31'));
        $item = PayrollItem::query()->where('payroll_run_id', $august->run->id)->where('employee_id', $this->aliceEmployee->id)->firstOrFail();
        $ghost = Payslip::query()->create([
            'payroll_item_id' => $item->id, 'payroll_period_id' => $august->id, 'employee_id' => $this->aliceEmployee->id,
            'payslip_number' => 'PS-GHOST-0001', 'sequence' => 1, 'issued_at' => now(),
        ]);

        $this->actingAsApi($this->alice)->getJson('/api/v1/payslips')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("/api/v1/payslips/{$ghost->id}")->assertNotFound();
        $this->getJson("/api/v1/payslips/{$ghost->id}/pdf")->assertNotFound();
        $this->assertSame('FINALIZED', $period->status->value);
    }

    public function test_hr_with_payslip_view_sees_everyone(): void
    {
        $this->toFinalized($this->period());
        $theirs = $this->slipOf($this->bobEmployee);

        $this->actingAsApi($this->hr)->getJson('/api/v1/payslips?scope=all')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson("/api/v1/payslips?scope=all&employee_id={$this->bobEmployee->id}")->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("/api/v1/payslips/{$theirs->id}")->assertOk();
        $this->get("/api/v1/payslips/{$theirs->id}/pdf?download=1")->assertOk()->assertDownload($theirs->payslip_number.'.pdf');

        [$manager] = $this->makeStaff('manager');
        $this->actingAsApi($manager)->getJson("/api/v1/payslips/{$theirs->id}")->assertNotFound();
    }

    public function test_employee_is_notified_once_and_missing_files_are_regenerated_idempotently(): void
    {
        $this->toFinalized($this->period());
        $slip = $this->slipOf($this->aliceEmployee);

        $this->assertSame(1, AppNotification::query()->where('user_id', $this->alice->id)->where('type', 'payslip.available')->count());
        $note = AppNotification::query()->where('user_id', $this->alice->id)->first();
        $this->assertSame('payslip.available.'.$slip->id, $note->dedupe_key);
        $this->assertSame($slip->id, $note->data['payslip_id']);

        // Nothing missing: the job is a no-op and does not rewrite files.
        $hash = $slip->file_sha256;
        Artisan::call('payroll:payslips', ['--verify' => true]);
        $this->assertSame($hash, $slip->fresh()->file_sha256);

        // File lost: --verify recreates exactly that one; no second notification.
        Storage::disk('local')->delete($slip->file_path);
        $this->assertSame(0, Artisan::call('payroll:payslips', ['--verify' => true]));
        Storage::disk('local')->assertExists($slip->fresh()->file_path);
        $this->assertStringContainsString('written: 1', Artisan::output());
        $this->assertFalse(app(PayslipDocuments::class)->ensure($slip->fresh()));
        $this->assertSame(1, AppNotification::query()->where('user_id', $this->alice->id)->where('type', 'payslip.available')->count());
    }
}
