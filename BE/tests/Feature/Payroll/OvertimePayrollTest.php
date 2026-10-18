<?php

namespace Tests\Feature\Payroll;

use App\Exceptions\ApiException;
use App\Exceptions\PayrollLockedException;
use App\Models\Employee;
use App\Models\OvertimePayrollInclusion;
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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Tests\Support\OvertimeFixtures;
use Tests\Support\PayrollFixtures;
use Tests\Support\WorkplaceFixtures;
use Tests\TestCase;

/**
 * Overtime -> payroll -> payslip through the rule engine. Worked example used throughout (demo values):
 * basic 5,190,000 / 173 = 30,000.00 per hour.
 */
class OvertimePayrollTest extends TestCase
{
    use OvertimeFixtures, PayrollFixtures, RefreshDatabase, WorkplaceFixtures;

    private Employee $ana;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpWorkplace();
        $this->setUpPayroll();
        $this->setUpOvertimeEngine();
        $this->at('2026-08-05 10:00');
        [, $this->ana] = $this->makeStaff();
        $this->giveSalary($this->ana, '5190000', '2026-01-01', ['TRANSPORT' => '50000', 'OVERTIME' => '40000', 'COOP' => '100000']);
    }

    private function item(PayrollPeriod $period, ?Employee $e = null): PayrollItem
    {
        return PayrollItem::query()->with('lines')->where('payroll_run_id', $period->run->id)->where('employee_id', ($e ?? $this->ana)->id)->firstOrFail();
    }

    /** @return Collection<int, PayrollItemLine> */
    private function overtimeLines(PayrollPeriod $period, ?Employee $e = null)
    {
        return $this->item($period, $e)->lines->where('source_key', 'overtime')->values();
    }

    private function liveInclusions(): int
    {
        return OvertimePayrollInclusion::query()->whereNotNull('active_request_id')->count();
    }

    public function test_worked_example_verified_overtime_becomes_traceable_lines_without_double_counting(): void
    {
        $this->worked($this->ana, '2026-07-14', '20:00');           // Tue: left 3 h after the 17:00 shift end
        $tue = $this->overtime($this->ana, '2026-07-14', 180);       // 17:00-20:00
        $this->worked($this->ana, '2026-07-18', '13:00');           // Sat (rest day)
        $sat = $this->overtime($this->ana, '2026-07-18', 240, '09:00');

        $july = $this->generate($this->period());
        $item = $this->item($july);
        $lines = $this->overtimeLines($july);

        $this->assertSame([
            ['Overtime – Workday ×1.5', 60, '1.00', '30000.00', '1.500', '45000.00'],
            ['Overtime – Workday ×2', 120, '2.00', '30000.00', '2.000', '120000.00'],
            ['Overtime – Rest day ×2', 240, '4.00', '30000.00', '2.000', '240000.00'],
        ], $lines->map(fn ($l) => [$l->component_name, $l->minutes, (string) $l->quantity, (string) $l->unit_amount, (string) $l->multiplier, (string) $l->amount])->all());
        $this->assertSame('SOURCE:OVERTIME', $lines[0]->calculation);
        $this->assertSame([$tue->id], array_column($lines[0]->source_refs, 'id'));
        $this->assertSame([$sat->id], array_column($lines[2]->source_refs, 'id'));

        // Legacy PER_OVERTIME_HOUR component is superseded: no OVERTIME component line, warning recorded.
        $this->assertNull($item->lines->first(fn ($l) => $l->salary_component_id === $this->components['OVERTIME']->id));
        $this->assertContains('OVERTIME_COMPONENT_SUPERSEDED:OVERTIME', $item->warnings);
        $this->assertSame(420, $item->overtime_minutes);
        // 5,190,000 basic + 2 days x 50,000 transport + 405,000 overtime
        $this->assertSame('5695000.00', (string) $item->gross_pay);
        $this->assertSame('5595000.00', (string) $item->net_pay);

        $this->assertSame(2, $this->liveInclusions());
        $inc = OvertimePayrollInclusion::query()->where('overtime_request_id', $tue->id)->firstOrFail();
        $this->assertSame(180, $inc->minutes);
        $this->assertSame('165000.00', (string) $inc->amount);
        $this->assertNull($inc->multiplier, 'two bands applied');
        $this->assertCount(2, $inc->segments);
        $this->assertSame($lines[0]->id, $inc->payroll_item_line_id);
        $this->assertSame('173.00', $inc->snapshot['rule']['rate_divisor']);
        $this->assertSame('workday', $inc->day_type);

        $tue->refresh();
        $this->assertSame('INCLUDED', $tue->payroll_status);
        $this->assertSame(180, $tue->verified_minutes);
        $this->assertSame('VERIFIED', $tue->verification_status);
        $this->assertSame('rest_day', $sat->refresh()->day_type);
    }

    public function test_eligible_is_min_of_approved_and_verified_and_the_verification_setting_decides_without_attendance(): void
    {
        $this->worked($this->ana, '2026-07-14', '18:00');             // left 1 h after shift end
        $partial = $this->overtime($this->ana, '2026-07-14', 120);     // approved 2 h, verified 1 h
        $missing = $this->overtime($this->ana, '2026-07-15', 60);      // no attendance at all

        $july = $this->generate($this->period());
        $lines = $this->overtimeLines($july);
        $this->assertCount(1, $lines);
        $this->assertSame(60, $lines[0]->minutes);
        $this->assertSame('45000.00', (string) $lines[0]->amount);
        $this->assertSame('PARTIAL', $partial->refresh()->verification_status);
        $this->assertSame(60, $partial->verified_minutes);
        $this->assertSame('PENDING_PAYROLL', $missing->refresh()->payroll_status);
        $excluded = collect($july->run->summary['earning_sources']['overtime']['excluded']);
        $this->assertSame('NO_ATTENDANCE', $excluded->firstWhere('overtime_request_id', $missing->id)['reason']);

        app(Settings::class)->set(['overtime.require_attendance_verification' => false]);
        $july = $this->generate($july);
        // both 60-min requests fall in the same (workday, x1.5) band -> one line of 120 min = 90,000
        $this->assertSame([120], $this->overtimeLines($july)->pluck('minutes')->all());
        $this->assertSame('90000.00', (string) $this->overtimeLines($july)[0]->amount);
        $this->assertSame('INCLUDED', $missing->refresh()->payroll_status);
        $this->assertSame('NOT_REQUIRED', $missing->verification_status);
    }

    public function test_pending_rejected_and_cancelled_overtime_is_never_paid(): void
    {
        foreach (['PENDING', 'REJECTED', 'CANCELLED'] as $i => $status) {
            $date = '2026-07-'.(14 + $i);
            $this->worked($this->ana, $date, '20:00');
            $this->overtime($this->ana, $date, 180, '17:00', $status);
        }

        $july = $this->generate($this->period());

        $this->assertCount(0, $this->overtimeLines($july));
        $this->assertSame(0, OvertimePayrollInclusion::query()->count());
    }

    public function test_regeneration_is_idempotent_and_two_periods_never_pay_the_same_request(): void
    {
        $this->worked($this->ana, '2026-07-14', '20:00');
        $req = $this->overtime($this->ana, '2026-07-14', 180);

        $july = $this->period();
        $this->generate($july);
        $this->generate($july);
        $july = $this->toReview($july);   // regenerate once more, then submit
        app(PayrollGenerator::class)->generate($this->hr, $july); // REVIEW -> regenerate again
        $july = $july->fresh('run');

        $this->assertSame(1, $this->liveInclusions());
        $this->assertSame(1, OvertimePayrollInclusion::query()->count(), 'old inclusions released, not piled up');
        $this->assertSame(2, $this->overtimeLines($july)->count());
        $this->assertSame(1, PayrollItemLine::query()->where('source_key', 'overtime')->where('minutes', 60)->count());

        $this->toFinalized($july);
        $this->assertNotNull(OvertimePayrollInclusion::query()->firstOrFail()->locked_at);

        $august = $this->generate($this->period('2026-08-01', '2026-08-31'));
        $this->assertCount(0, $this->overtimeLines($august), 'already paid in July');
        $this->assertSame(1, OvertimePayrollInclusion::query()->where('overtime_request_id', $req->id)->count());
    }

    public function test_cancel_releases_and_return_to_draft_releases_and_forces_regeneration(): void
    {
        $this->worked($this->ana, '2026-07-14', '20:00');
        $req = $this->overtime($this->ana, '2026-07-14', 180);

        $july = $this->toReview($this->period());
        $this->assertSame('INCLUDED', $req->refresh()->payroll_status);

        app(PayrollWorkflow::class)->returnToDraft($this->hr, $july, 'Check overtime');
        $this->assertSame(0, $this->liveInclusions());
        $this->assertSame('PENDING_PAYROLL', $req->refresh()->payroll_status);
        try {
            app(PayrollWorkflow::class)->submit($this->hr, $july);
            $this->fail('stale run submitted');
        } catch (ApiException $e) {
            $this->assertSame('PAYROLL_EMPTY', $e->errorCode);
        }

        $this->generate($july);
        $this->assertSame(1, $this->liveInclusions());
        app(PayrollWorkflow::class)->cancel($this->hr, $july, 'Wrong dates');
        $this->assertSame(0, $this->liveInclusions());
        $this->assertSame('PENDING_PAYROLL', $req->refresh()->payroll_status);

        // A replacement July period can now pay it.
        $again = $this->generate($this->period());
        $this->assertCount(2, $this->overtimeLines($again));
    }

    public function test_late_approval_after_finalization_is_paid_in_the_next_period_never_by_editing_the_finalized_run(): void
    {
        $this->worked($this->ana, '2026-07-28', '20:00');
        $late = $this->overtime($this->ana, '2026-07-28', 180, '17:00', 'PENDING');

        $july = $this->toFinalized($this->period());
        $julyNet = (string) $this->item($july)->net_pay;
        $this->assertCount(0, $this->overtimeLines($july));

        $late->fill(['status' => 'APPROVED', 'approved_minutes' => 180, 'payroll_status' => 'PENDING_PAYROLL'])->save();

        $august = $this->generate($this->period('2026-08-01', '2026-08-31'));
        $lines = $this->overtimeLines($august);
        $this->assertSame(['45000.00', '120000.00'], $lines->map(fn ($l) => (string) $l->amount)->all());
        $this->assertTrue($lines[0]->source_refs[0]['carried_over']);
        $this->assertSame($julyNet, (string) $this->item($july->fresh('run'))->net_pay, 'finalized July untouched');
        $this->assertSame('FINALIZED', $july->fresh()->status->value);
    }

    public function test_late_approval_can_be_paid_by_a_controlled_adjustment_period_instead(): void
    {
        app(Settings::class)->set(['overtime.carry_over_late_approvals' => false]);
        $this->worked($this->ana, '2026-07-28', '20:00');
        $late = $this->overtime($this->ana, '2026-07-28', 180, '17:00', 'PENDING');
        $july = $this->toFinalized($this->period());
        $late->fill(['status' => 'APPROVED', 'approved_minutes' => 180, 'payroll_status' => 'PENDING_PAYROLL'])->save();

        $august = $this->generate($this->period('2026-08-01', '2026-08-31'));
        $this->assertCount(0, $this->overtimeLines($august), 'carry-over switched off');

        $adjustment = app(PayrollPeriodService::class)->createAdjustment($this->hr, $july, 'Overtime approved late');
        $adjustment = $this->toFinalized($adjustment);
        $item = $this->item($adjustment);
        $this->assertSame('165000.00', (string) $item->gross_pay);
        $this->assertNotContains('NO_BASIC_SALARY', $item->warnings ?? []);
        $inclusion = OvertimePayrollInclusion::query()->where('overtime_request_id', $late->id)->firstOrFail();
        $this->assertSame($adjustment->run->id, $inclusion->payroll_run_id);
        $this->assertNotNull($inclusion->locked_at);
        $this->assertNotNull(Payslip::query()->where('payroll_item_id', $item->id)->first());
    }

    public function test_finalized_overtime_snapshot_survives_rule_and_salary_changes_and_cannot_be_edited(): void
    {
        $this->worked($this->ana, '2026-07-14', '20:00');
        $this->overtime($this->ana, '2026-07-14', 180);
        $july = $this->toFinalized($this->period());
        $slip = Payslip::query()->where('employee_id', $this->ana->id)->firstOrFail();
        $before = app(PayslipDocuments::class)->html($slip);

        $this->rule->fill(['rate_divisor' => '100', 'multiplier_tiers' => ['workday' => [['multiplier' => '5']]]])->save();
        app(EmployeeSalaryService::class)->change($this->hr, $this->ana, '2026-07-01', [
            ['salary_component_id' => $this->components['BASIC']->id, 'amount' => '9000000'],
        ], 'Retro raise attempt');

        $this->assertSame($before, app(PayslipDocuments::class)->html($slip->fresh()));
        $inc = OvertimePayrollInclusion::query()->firstOrFail();
        $this->assertSame('30000.00', (string) $inc->rate);
        $this->assertSame('5190000.00', $inc->snapshot['rate_basis_amount']);

        $this->expectException(PayrollLockedException::class);
        $inc->delete();
    }

    public function test_engine_off_keeps_the_legacy_per_overtime_hour_component(): void
    {
        app(Settings::class)->set(['overtime.engine_enabled' => false]);
        $this->overtime($this->ana, '2026-07-14', 90); // no attendance needed in legacy mode

        $july = $this->generate($this->period());
        $item = $this->item($july);

        $this->assertSame('60000.00', (string) $item->lines->firstWhere('component_code', 'OVERTIME')->amount); // 40,000 x 1.5 h
        $this->assertCount(0, $this->overtimeLines($july));
        $this->assertSame(0, OvertimePayrollInclusion::query()->count());
        $this->assertSame(90, $item->overtime_minutes);
    }

    public function test_day_cap_applies_across_requests_of_the_same_day(): void
    {
        $this->rule->fill(['max_minutes_per_day' => 150])->save();
        $this->worked($this->ana, '2026-07-14', '21:00');
        $this->overtime($this->ana, '2026-07-14', 120);              // 17:00-19:00
        $second = $this->overtime($this->ana, '2026-07-14', 120, '19:00'); // 19:00-21:00, only 30 min left

        $july = $this->generate($this->period());

        $this->assertSame(150, $this->item($july)->overtime_minutes);
        $this->assertSame(30, OvertimePayrollInclusion::query()->where('overtime_request_id', $second->id)->value('minutes'));
        $this->assertContains('CAPPED_DAY', OvertimePayrollInclusion::query()->where('overtime_request_id', $second->id)->first()->snapshot['notes']);
    }

    public function test_payslip_json_and_pdf_show_hours_rate_multiplier_and_amount(): void
    {
        $user = $this->ana->user;
        $this->worked($this->ana, '2026-07-14', '20:00');
        $req = $this->overtime($this->ana, '2026-07-14', 180);
        $this->toFinalized($this->period());
        $slip = Payslip::query()->where('employee_id', $this->ana->id)->firstOrFail();

        $json = $this->actingAsApi($user)->getJson("/api/v1/payslips/{$slip->id}")->assertOk();
        $overtime = collect($json->json('data.item.earnings'))->where('code', 'OVERTIME')->values();
        $this->assertSame(['1.00', '2.00'], $overtime->pluck('source.hours')->all());
        $this->assertSame(['30000.00', '30000.00'], $overtime->pluck('source.rate')->all());
        $this->assertSame(['1.500', '2.000'], $overtime->pluck('source.multiplier')->all());
        $this->assertSame(['45000.00', '120000.00'], $overtime->pluck('amount')->all());
        $this->assertSame($req->id, $overtime[0]['source']['refs'][0]['id']);

        $html = app(PayslipDocuments::class)->html($slip);
        $this->assertStringContainsString('Overtime – Workday ×1.5', $html);
        $this->assertStringContainsString('1.00 h × 30,000.00/h × 1.5 = 45,000', $html);
        $this->assertStringContainsString('2.00 h × 30,000.00/h × 2 = 120,000', $html);
        $this->assertStringContainsString('14 Jul: 60 min', $html);
        $this->assertStringContainsString('14 Jul: 120 min', $html);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($slip->fresh()->file_path));

        // The overtime request shows its payroll reference to its owner.
        $this->getJson("/api/v1/overtime-requests/{$req->id}")->assertOk()
            ->assertJsonPath('data.payroll_status', 'INCLUDED')
            ->assertJsonPath('data.verified_minutes', 180)
            ->assertJsonPath('data.payroll.amount', '165000.00')
            ->assertJsonPath('data.payroll.payslip.payslip_number', $slip->payslip_number);
    }
}
