<?php

namespace Tests\Feature\Reimbursements;

use App\Exceptions\ApiException;
use App\Models\ExpensePayment;
use App\Services\Reimbursements\PayrollRouting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReimbursementFixtures;
use Tests\TestCase;

/** PayrollRouting hook (not yet wired into payroll) and the reimbursement reports. */
class ReimbursementPayrollAndReportsTest extends TestCase
{
    use RefreshDatabase, ReimbursementFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReimbursements();
        $this->at('2026-10-10 10:00');
    }

    /** Minimal payroll rows so the FK columns can point at something real. @return array{0: int, 1: int} [periodId, lineId] */
    private function payrollLine(string $code = '2026-10'): array
    {
        $periodId = DB::table('payroll_periods')->insertGetId([
            'company_id' => $this->company->id, 'type' => 'REGULAR', 'code' => $code, 'name' => "Payroll {$code}",
            'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'status' => 'DRAFT', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $runId = DB::table('payroll_runs')->insertGetId(['payroll_period_id' => $periodId, 'currency' => 'IDR', 'created_at' => now(), 'updated_at' => now()]);
        $itemId = DB::table('payroll_items')->insertGetId([
            'payroll_run_id' => $runId, 'employee_id' => $this->employee->id, 'employee_number' => $this->employee->employee_number,
            'employee_name' => $this->employee->full_name, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $line = fn () => DB::table('payroll_item_lines')->insertGetId([
            'payroll_item_id' => $itemId, 'component_code' => 'REIMB', 'component_name' => 'Reimbursement', 'kind' => 'EARNING',
            'calculation' => 'MANUAL_ADJUSTMENT', 'amount' => 100000, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$periodId, $line(), $line()];
    }

    private function expectCode(string $code, callable $fn): void
    {
        try {
            $fn();
            $this->fail("expected {$code}");
        } catch (ApiException $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    public function test_payroll_routing_includes_a_claim_exactly_once(): void
    {
        $this->setPolicy(['payment_route' => 'PAYROLL']);
        $routing = app(PayrollRouting::class);
        $claim = $this->approved();
        $draft = $this->makeClaim(submit: false);
        $this->underReview();

        $eligible = $routing->eligibleForPayroll($this->employee, '2026-10-01', '2026-10-31');
        $this->assertSame([$claim->id], $eligible->pluck('id')->all());
        $this->assertSame([], $routing->eligibleForPayroll($this->employee, '2026-09-01', '2026-09-30')->pluck('id')->all(), 'approved after the period end');
        $this->assertSame([], $routing->eligibleForPayroll($this->strangerEmployee, '2026-10-01', '2026-10-31')->all());

        [$periodId, $lineId, $otherLine] = $this->payrollLine();
        $included = $routing->markIncluded($claim, $lineId, $periodId);
        $this->assertSame('SCHEDULED_FOR_PAYMENT', $included->status->value);
        $this->assertSame($lineId, (int) $included->payroll_item_line_id);

        $again = $routing->markIncluded($claim, $lineId, $periodId); // idempotent replay
        $this->assertSame($lineId, (int) $again->payroll_item_line_id);
        $this->expectCode('PAYROLL_ALREADY_INCLUDED', fn () => $routing->markIncluded($claim, $otherLine, $periodId));
        $this->assertSame(1, $claim->events()->where('action', 'payroll_included')->count());
        $this->assertSame([], $routing->eligibleForPayroll($this->employee, '2026-10-01', '2026-10-31')->all(), 'never offered twice');
        $this->expectCode('INVALID_TRANSITION', fn () => $routing->markIncluded($draft, $otherLine, $periodId));

        // Included claims cannot be cancelled or paid separately; settlement marks them PAID once.
        $this->actingAsApi($this->hr)->postJson("/api/v1/reimbursements/{$claim->id}/cancel", ['reason' => 'x'])->assertStatus(409);
        $this->postJson("/api/v1/reimbursements/{$claim->id}/pay", ['idempotency_key' => 'x', 'method' => 'CASH'])->assertStatus(409);
        $this->assertSame(1, $routing->settlePeriod($periodId, $this->hr));
        $this->assertSame(0, $routing->settlePeriod($periodId, $this->hr));
        $this->assertSame('PAID', $claim->fresh()->status->value);
        $this->assertSame('PAYROLL', ExpensePayment::query()->where('expense_request_id', $claim->id)->value('method'));
    }

    public function test_separate_route_and_separately_paid_claims_never_enter_payroll(): void
    {
        $routing = app(PayrollRouting::class);
        $separate = $this->approved();
        $paid = $this->paid();
        [$periodId, $lineId] = $this->payrollLine();

        $this->assertSame([], $routing->eligibleForPayroll($this->employee, '2026-10-01', '2026-10-31')->all());
        $this->expectCode('PAYMENT_ROUTE_SEPARATE', fn () => $routing->markIncluded($separate, $lineId, $periodId));
        $this->expectCode('ALREADY_PAID_SEPARATELY', fn () => $routing->markIncluded($paid, $lineId, $periodId));

        // Even if the company switches to PAYROLL later, a claim approved under SEPARATE keeps its route.
        $this->setPolicy(['payment_route' => 'PAYROLL']);
        $this->assertSame([], $routing->eligibleForPayroll($this->employee, '2026-10-01', '2026-10-31')->all());
        $this->assertNull($separate->fresh()->payroll_item_line_id);
    }

    // ------------------------------------------------------------------ reports

    private function url(string $key, array $query = []): string
    {
        return "/api/v1/reports/{$key}?".http_build_query(['from' => '2026-10-01', 'to' => '2026-10-31'] + $query);
    }

    public function test_reports_are_scoped_and_aggregate_real_rows(): void
    {
        $this->paid();                                                       // employee: 100000 paid
        $this->approved(overrides: ['submitted_amount' => '50000']);         // employee: approved
        $this->makeClaim($this->strangerEmployee, ['submitted_amount' => '70000', 'expense_category_id' => $this->category('MEALS')->id]);
        $this->makeClaim(submit: false);                                     // drafts never appear

        // HR: everything that is not a draft.
        $this->actingAsApi($this->hr)->getJson($this->url('reimbursements.requests'))->assertOk()->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.totals.submitted', '220000.00');
        $byCategory = collect($this->getJson($this->url('reimbursements.by_category'))->assertOk()->json('data.rows'))->keyBy('category');
        $this->assertSame(2, $byCategory['Transport']['claims']);
        $this->assertSame('150000.00', $byCategory['Transport']['approved_total']);
        $this->assertSame('100000.00', $byCategory['Transport']['paid_total']);
        $this->assertSame('0.00', $byCategory['Meals & Entertainment']['approved_total']);
        $byStatus = collect($this->getJson($this->url('reimbursements.by_status'))->json('data.rows'))->pluck('claims', 'status')->all();
        $this->assertSame(['APPROVED' => 1, 'PAID' => 1, 'SUBMITTED' => 1], $byStatus);
        $this->getJson($this->url('reimbursements.by_department'))->assertOk()->assertJsonPath('data.rows.0.claims', 3);
        $this->getJson($this->url('reimbursements.by_employee', ['status' => 'PAID']))->assertOk()->assertJsonPath('meta.total', 1);
        $this->get('/api/v1/reports/reimbursements.requests/export/csv?from=2026-10-01&to=2026-10-31')->assertOk();

        // Manager: only the direct report.
        $rows = $this->actingAsApi($this->manager)->getJson($this->url('reimbursements.by_employee'))->assertOk()->json('data.rows');
        $this->assertSame([$this->employee->employee_number], array_column($rows, 'employee_number'));
        $this->getJson($this->url('reimbursements.requests', ['employee_id' => $this->strangerEmployee->id]))->assertOk()->assertJsonPath('meta.total', 0);

        // Employees have no report access at all (unknown and forbidden reports look the same).
        $this->actingAsApi($this->employeeUser)->getJson($this->url('reimbursements.requests'))->assertNotFound();
        $keys = collect($this->actingAsApi($this->manager)->getJson('/api/v1/reports')->json('data'))->pluck('key');
        $this->assertTrue($keys->contains('reimbursements.by_category'));
    }
}
