<?php

namespace Tests\Feature\Payroll;

use App\Models\Activity;
use App\Models\PayrollItem;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PayrollFixtures;
use Tests\Support\WorkplaceFixtures;
use Tests\TestCase;

class PayrollAdjustmentTest extends TestCase
{
    use PayrollFixtures, RefreshDatabase, WorkplaceFixtures;

    private const BASE = '/api/v1/payroll-periods';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpWorkplace();
        $this->setUpPayroll();
        $this->at('2026-08-10 10:00');
    }

    public function test_adjustment_period_corrects_finalized_payroll_through_the_same_workflow(): void
    {
        [$user, $e] = $this->makeStaff();
        [, $other] = $this->makeStaff();
        [, $notPaid] = $this->makeStaff();
        $this->giveSalary($e, '8000000');
        $this->giveSalary($other, '7000000');
        $original = $this->toFinalized($this->period());
        $originalNet = (string) PayrollItem::query()->where('employee_id', $e->id)->value('net_pay');

        $this->actingAsApi($this->hr);
        $id = $this->postJson(self::BASE."/{$original->id}/adjustments", ['reason' => 'Raise approved late, effective July'])
            ->assertCreated()->assertJsonPath('data.type', 'ADJUSTMENT')->assertJsonPath('data.code', '2026-07-A1')
            ->assertJsonPath('data.adjusts_payroll_period_id', $original->id)->assertJsonPath('data.original.code', '2026-07')
            ->json('data.id');

        $entry = $this->postJson(self::BASE."/{$id}/adjustment-entries", [
            'employee_id' => $e->id, 'kind' => 'EARNING', 'label' => 'Back pay July', 'amount' => 1000000, 'reason' => 'Raise 8.0M -> 9.0M from July',
        ])->assertCreated()->json('data.id');
        $this->postJson(self::BASE."/{$id}/adjustment-entries", [
            'employee_id' => $e->id, 'kind' => 'DEDUCTION', 'label' => 'Meal overpaid', 'amount' => 35000, 'reason' => 'Duplicate meal day',
        ])->assertCreated();
        $this->postJson(self::BASE."/{$id}/adjustment-entries", [
            'employee_id' => $notPaid->id, 'kind' => 'EARNING', 'label' => 'X', 'amount' => 1, 'reason' => 'Not in July payroll',
        ])->assertStatus(422)->assertJsonPath('error.code', 'EMPLOYEE_NOT_IN_ORIGINAL_PAYROLL');
        $this->getJson(self::BASE."/{$id}/adjustment-entries")->assertOk()->assertJsonCount(2, 'data');

        $this->postJson(self::BASE."/{$id}/generate")->assertOk()->assertJsonPath('data.run.employee_count', 1)
            ->assertJsonPath('data.run.total_net', '965000.00');
        $item = PayrollItem::query()->whereHas('run', fn ($q) => $q->where('payroll_period_id', $id))->with('lines')->firstOrFail();
        $this->assertSame($e->id, $item->employee_id);
        $this->assertNotNull($item->adjusts_payroll_item_id);
        $this->assertSame('MANUAL_ADJUSTMENT', $item->lines->first()->calculation);
        $this->assertSame('Raise 8.0M -> 9.0M from July', $item->lines->first()->adjustment_reason);

        // changing entries after generation drops the stale items: must regenerate before submit
        $this->deleteJson(self::BASE."/{$id}/adjustment-entries/{$entry}")->assertOk();
        $this->postJson(self::BASE."/{$id}/submit")->assertStatus(422)->assertJsonPath('error.code', 'PAYROLL_EMPTY');
        $this->postJson(self::BASE."/{$id}/adjustment-entries", [
            'employee_id' => $e->id, 'kind' => 'EARNING', 'label' => 'Back pay July', 'amount' => 1000000, 'reason' => 'Raise 8.0M -> 9.0M from July',
        ])->assertCreated();
        $this->postJson(self::BASE."/{$id}/generate")->assertOk();
        $this->postJson(self::BASE."/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'REVIEW');

        // entries are frozen outside DRAFT
        $this->postJson(self::BASE."/{$id}/adjustment-entries", [
            'employee_id' => $e->id, 'kind' => 'EARNING', 'label' => 'Late', 'amount' => 5, 'reason' => 'Too late now',
        ])->assertStatus(409);

        $this->actingAsApi($this->admin)->postJson(self::BASE."/{$id}/approve")->assertOk();
        $this->postJson(self::BASE."/{$id}/finalize")->assertOk()->assertJsonPath('data.status', 'FINALIZED');

        $slip = Payslip::query()->where('payroll_period_id', $id)->firstOrFail();
        $this->assertSame('PS-202607A1-0001', $slip->payslip_number);
        Storage::disk('local')->assertExists($slip->file_path);
        $this->assertSame($originalNet, (string) PayrollItem::query()->where('employee_id', $e->id)->where('payroll_run_id', $original->run->id)->value('net_pay'), 'original untouched');

        $this->actingAsApi($user)->getJson('/api/v1/payslips')->assertOk()->assertJsonPath('meta.total', 2);

        foreach (['payroll_adjustment.period_created', 'payroll_adjustment.entry_added', 'payroll_adjustment.entry_removed'] as $action) {
            $this->assertTrue(Activity::query()->where('action', $action)->exists(), $action);
        }
    }

    public function test_only_finalized_regular_payroll_can_be_adjusted(): void
    {
        [, $e] = $this->makeStaff();
        $this->giveSalary($e, '8000000');
        $draft = $this->period();

        $this->actingAsApi($this->hr)->postJson(self::BASE."/{$draft->id}/adjustments", ['reason' => 'Too early'])
            ->assertStatus(409)->assertJsonPath('error.code', 'PERIOD_NOT_FINALIZED');
        $this->postJson(self::BASE."/{$draft->id}/adjustments")->assertStatus(422);

        $this->toFinalized($draft);
        $adj = $this->postJson(self::BASE."/{$draft->id}/adjustments", ['reason' => 'First'])->assertCreated()->json('data.id');
        $this->postJson(self::BASE."/{$adj}/adjustments", ['reason' => 'Nested'])->assertStatus(422)->assertJsonPath('error.code', 'INVALID_ADJUSTMENT_TARGET');
        $this->postJson(self::BASE."/{$draft->id}/adjustments", ['reason' => 'Second'])->assertCreated()->assertJsonPath('data.code', '2026-07-A2');
        $this->postJson(self::BASE."/{$draft->id}/adjustment-entries", [
            'employee_id' => $e->id, 'kind' => 'EARNING', 'label' => 'X', 'amount' => 1, 'reason' => 'Regular period',
        ])->assertStatus(422)->assertJsonPath('error.code', 'NOT_AN_ADJUSTMENT');
        $this->assertSame(3, PayrollPeriod::query()->count());
    }
}
