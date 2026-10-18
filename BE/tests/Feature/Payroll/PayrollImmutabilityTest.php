<?php

namespace Tests\Feature\Payroll;

use App\Exceptions\ApiException;
use App\Exceptions\PayrollLockedException;
use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\PayrollItemLine;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Services\Payroll\EmployeeSalaryService;
use App\Services\Payroll\PayrollGenerator;
use App\Services\Payroll\PayrollPeriodService;
use App\Services\Payroll\PayrollWorkflow;
use App\Services\Payroll\PayslipDocuments;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PayrollFixtures;
use Tests\Support\WorkplaceFixtures;
use Tests\TestCase;

class PayrollImmutabilityTest extends TestCase
{
    use PayrollFixtures, RefreshDatabase, WorkplaceFixtures;

    private PayrollPeriod $period;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpWorkplace();
        $this->setUpPayroll();
        $this->at('2026-08-05 10:00');
        [, $this->employee] = $this->makeStaff();
        $this->giveSalary($this->employee, '8000000');
        $this->period = $this->toFinalized($this->period());
    }

    private function assertLocked(callable $fn, string $what): void
    {
        try {
            $fn();
            $this->fail("{$what} was allowed on finalized payroll");
        } catch (PayrollLockedException $e) {
            $this->assertSame('PAYROLL_LOCKED', $e->errorCode);
            $this->assertSame(409, $e->status);
        }
    }

    public function test_model_guards_refuse_edits_of_finalized_payroll(): void
    {
        $item = PayrollItem::query()->firstOrFail();
        $line = PayrollItemLine::query()->firstOrFail();
        $slip = Payslip::query()->firstOrFail();
        $run = $this->period->run;

        $this->assertLocked(fn () => $item->update(['net_pay' => '1']), 'item update');
        $this->assertLocked(fn () => $line->update(['amount' => '1']), 'line update');
        $this->assertLocked(fn () => $slip->update(['payslip_number' => 'X']), 'payslip renumber');
        $this->assertLocked(fn () => $slip->update(['file_path' => 'elsewhere.pdf']), 'payslip file swap');
        $this->assertLocked(fn () => $run->update(['total_net' => '1']), 'run update');
        $this->assertLocked(fn () => $run->update(['status' => 'DRAFT']), 'run reopen');
        $this->assertLocked(fn () => $this->period->update(['name' => 'Renamed']), 'period update');
        $this->assertLocked(fn () => PayrollItem::query()->create(['payroll_run_id' => $run->id, 'employee_id' => $this->employee->id, 'employee_number' => 'X', 'employee_name' => 'X']), 'item insert');

        $this->assertSame('7900000.00', (string) $item->fresh()->net_pay);
    }

    public function test_model_guards_refuse_deletes_of_finalized_payroll(): void
    {
        $this->assertLocked(fn () => PayrollItem::query()->firstOrFail()->delete(), 'item delete');
        $this->assertLocked(fn () => PayrollItemLine::query()->firstOrFail()->delete(), 'line delete');
        $this->assertLocked(fn () => Payslip::query()->firstOrFail()->delete(), 'payslip delete');
        $this->assertLocked(fn () => $this->period->run->delete(), 'run delete');
        $this->assertLocked(fn () => $this->period->delete(), 'period delete');

        $this->assertSame(1, PayrollItem::query()->count());
        $this->assertSame(1, Payslip::query()->count());
    }

    public function test_services_refuse_to_touch_finalized_payroll(): void
    {
        foreach ([
            fn () => app(PayrollGenerator::class)->generate($this->hr, $this->period),
            fn () => app(PayrollWorkflow::class)->cancel($this->hr, $this->period, 'Try'),
            fn () => app(PayrollWorkflow::class)->returnToDraft($this->admin, $this->period, 'Try'),
            fn () => app(PayrollPeriodService::class)->update($this->hr, $this->period, ['name' => 'X']),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A service changed finalized payroll');
            } catch (ApiException $e) {
                $this->assertSame(409, $e->status);
            }
        }

        $this->actingAsApi($this->admin)->patchJson("/api/v1/payroll-periods/{$this->period->id}", ['name' => 'X'])
            ->assertStatus(409)->assertJsonPath('error.code', 'PAYROLL_LOCKED');
    }

    public function test_no_delete_route_exists_for_payroll_records(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (! in_array('DELETE', $route->methods(), true)) {
                continue;
            }
            $uri = $route->uri();
            $this->assertDoesNotMatchRegularExpression('#^api/v1/(payroll-periods/\{[^}]+\}|payroll-runs|payroll-items|payslips)(/|$)(?!adjustment-entries)#', $uri, "DELETE {$uri}");
        }

        $slip = Payslip::query()->firstOrFail();
        $this->actingAsApi($this->admin);
        $this->deleteJson("/api/v1/payslips/{$slip->id}")->assertStatus(405);
        $this->deleteJson("/api/v1/payroll-periods/{$this->period->id}")->assertStatus(405);
        $this->deleteJson("/api/v1/payroll-items/{$slip->payroll_item_id}")->assertStatus(405);
        $this->deleteJson("/api/v1/payroll-runs/{$this->period->run->id}")->assertStatus(405);
    }

    public function test_salary_and_settings_changes_after_finalization_leave_items_and_payslip_untouched(): void
    {
        $item = PayrollItem::query()->with('lines')->firstOrFail();
        $slip = Payslip::query()->firstOrFail();
        $before = [$item->only(['basic_salary', 'gross_pay', 'total_deductions', 'net_pay']), $item->lines->pluck('amount', 'component_code')->all()];
        $hash = $slip->file_sha256;
        $pdfBytes = Storage::disk('local')->get($slip->file_path);

        // Back-dated raise into the finalized month + currency/rounding/prefix changes.
        $result = app(EmployeeSalaryService::class)->change($this->hr, $this->employee, '2026-07-01', [
            ['salary_component_id' => $this->components['BASIC']->id, 'amount' => '12000000'],
        ], 'Retro raise');
        $this->assertSame('AFFECTS_FINALIZED_PAYROLL', $result['warnings'][0]['code']);
        $this->assertSame(['2026-07'], $result['warnings'][0]['periods']);
        app(Settings::class)->set(['general.currency' => 'USD', 'payroll.rounding_decimals' => '2', 'payroll.payslip_prefix' => 'ZZ']);
        $this->components['COOP']->update(['name' => 'Renamed component']);

        $item->refresh()->load('lines');
        $this->assertSame($before, [$item->only(['basic_salary', 'gross_pay', 'total_deductions', 'net_pay']), $item->lines->pluck('amount', 'component_code')->all()]);
        $this->assertSame('Cooperative Savings', $item->lines->firstWhere('component_code', 'COOP')->component_name);
        $this->assertSame('IDR', $this->period->run->fresh()->currency);
        $this->assertSame($hash, $slip->fresh()->file_sha256);

        // Losing the file and re-rendering it reproduces the slip from the snapshot only.
        Storage::disk('local')->delete($slip->file_path);
        $this->assertTrue(app(PayslipDocuments::class)->ensure($slip->fresh()));
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($slip->fresh()->file_path));
        $this->assertNotEmpty($pdfBytes);

        $html = app(PayslipDocuments::class)->html($slip->fresh());
        $this->assertStringContainsString('IDR 7,900,000', $html);
        $this->assertStringContainsString('Cooperative Savings', $html);
        $this->assertStringNotContainsString('12,000,000', $html);
        $this->assertStringContainsString('PS-202607-0001', $html);
    }
}
