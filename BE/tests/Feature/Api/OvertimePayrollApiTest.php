<?php

namespace Tests\Feature\Api;

use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\PayrollItem;
use App\Models\PayrollItemLine;
use App\Models\User;
use App\Services\Attendance\OvertimeService;
use App\Services\Payroll\PayrollWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\OvertimeFixtures;
use Tests\Support\PayrollFixtures;
use Tests\Support\WorkplaceFixtures;
use Tests\TestCase;

/** Overtime rules API, overtime request payroll fields, scope / IDOR and the controlled void action. */
class OvertimePayrollApiTest extends TestCase
{
    use OvertimeFixtures, PayrollFixtures, RefreshDatabase, WorkplaceFixtures;

    private const RULES = '/api/v1/overtime-rules';

    private User $manager;

    private User $staff;

    private Employee $staffEmployee;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpWorkplace();
        $this->setUpPayroll();
        $this->setUpOvertimeEngine();
        $this->at('2026-08-05 10:00');
        [$this->manager, $managerEmployee] = $this->makeStaff('manager');
        [$this->staff, $this->staffEmployee] = $this->makeStaff('employee', [], ['manager_id' => $managerEmployee->id]);
        [$this->other] = $this->makeStaff();
        $this->giveSalary($this->staffEmployee, '5190000', '2026-01-01', []);
    }

    /** @return array<string, mixed> */
    private function ruleBody(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Weekend crew', 'effective_from' => '2026-08-01', 'rate_basis' => 'BASIC', 'rate_divisor' => 160,
            'multiplier_tiers' => ['workday' => [['up_to_hours' => 1, 'multiplier' => 1.5], ['multiplier' => 2]], 'rest_day' => [['multiplier' => 2]]],
            'rounding_minutes' => 15, 'rounding_direction' => 'DOWN', 'max_minutes_per_day' => 240,
        ], $overrides);
    }

    public function test_rules_crud_requires_overtime_or_payroll_manage(): void
    {
        $this->actingAsApi($this->staff)->getJson(self::RULES)->assertForbidden();
        $this->actingAsApi($this->manager)->postJson(self::RULES, $this->ruleBody())->assertForbidden();

        $id = $this->actingAsApi($this->hr)->postJson(self::RULES, $this->ruleBody())->assertCreated()
            ->assertJsonPath('data.rate_divisor', '160.00')
            ->assertJsonPath('data.rounding_minutes', 15)
            ->json('data.id');
        $this->getJson(self::RULES)->assertOk()->assertJsonCount(2, 'data');
        $this->patchJson(self::RULES."/{$id}", ['is_active' => false, 'max_minutes_per_period' => 2400])->assertOk()
            ->assertJsonPath('data.is_active', false)->assertJsonPath('data.max_minutes_per_period', 2400);

        $this->postJson(self::RULES, $this->ruleBody(['multiplier_tiers' => ['weekend' => [['multiplier' => 2]]]]))->assertStatus(422);
        $this->postJson(self::RULES, $this->ruleBody(['multiplier_tiers' => ['workday' => [['multiplier' => 0]]]]))->assertStatus(422);
        $this->postJson(self::RULES, $this->ruleBody(['effective_to' => '2026-07-01']))->assertStatus(422);

        $this->deleteJson(self::RULES."/{$id}")->assertOk();
        $this->assertDatabaseMissing('overtime_rules', ['id' => $id]);
    }

    public function test_a_rule_that_paid_overtime_cannot_be_deleted(): void
    {
        $this->worked($this->staffEmployee, '2026-07-14', '20:00');
        $this->overtime($this->staffEmployee, '2026-07-14', 120);
        $this->generate($this->period());

        $this->actingAsApi($this->hr)->deleteJson(self::RULES."/{$this->rule->id}")->assertStatus(409)->assertJsonPath('error.code', 'RULE_IN_USE');
    }

    public function test_overtime_request_shows_minutes_and_payroll_reference_only_to_entitled_viewers(): void
    {
        $this->worked($this->staffEmployee, '2026-07-14', '18:30');
        $request = app(OvertimeService::class)->request($this->staffEmployee, $this->staff, '2026-07-14',
            CarbonImmutable::parse('2026-07-14 17:00', 'Asia/Jakarta')->utc(), CarbonImmutable::parse('2026-07-14 19:00', 'Asia/Jakarta')->utc(), 'Deploy');
        app(OvertimeService::class)->approve($this->manager, $request, 120, 'ok');
        $request->refresh();
        $this->assertSame('PENDING_PAYROLL', $request->payroll_status);
        $this->assertSame(90, $request->verified_minutes, 'checked out 18:30: 90 of the approved 120 minutes verified');
        $this->assertSame('PARTIAL', $request->verification_status);
        $this->assertSame('workday', $request->day_type);

        $this->toFinalized($this->period());

        $this->actingAsApi($this->staff)->getJson("/api/v1/overtime-requests/{$request->id}")->assertOk()
            ->assertJsonPath('data.requested_minutes', 120)->assertJsonPath('data.approved_minutes', 120)
            ->assertJsonPath('data.verified_minutes', 90)->assertJsonPath('data.payroll_status', 'INCLUDED')
            ->assertJsonPath('data.payroll.minutes', 90)->assertJsonPath('data.payroll.amount', '75000.00') // 60 x1.5 + 30 x2 at 30,000/h
            ->assertJsonPath('data.payroll.payroll_period.code', '2026-07');

        // Manager approves for the team but does not see pay figures.
        $this->actingAsApi($this->manager)->getJson("/api/v1/overtime-requests/{$request->id}")->assertOk()
            ->assertJsonPath('data.payroll_status', 'INCLUDED')->assertJsonPath('data.payroll', null);
        $this->actingAsApi($this->manager)->getJson('/api/v1/overtime-requests?scope=review&payroll_status=INCLUDED')->assertOk()
            ->assertJsonPath('data.0.id', $request->id)->assertJsonPath('data.0.payroll', null);

        // Another employee: no access (IDOR).
        $this->actingAsApi($this->other)->getJson("/api/v1/overtime-requests/{$request->id}")->assertForbidden();

        // Payroll item detail (payroll.view) shows the overtime line with its source.
        $item = PayrollItem::query()->where('employee_id', $this->staffEmployee->id)->firstOrFail();
        $earnings = collect($this->actingAsApi($this->hr)->getJson("/api/v1/payroll-items/{$item->id}")->assertOk()->json('data.earnings'));
        $line = $earnings->firstWhere('name', 'Overtime – Workday ×1.5');
        $this->assertSame(['key' => 'overtime', 'minutes' => 60, 'hours' => '1.00', 'rate' => '30000.00', 'multiplier' => '1.500', 'amount' => '45000.00'],
            array_intersect_key($line['source'], array_flip(['key', 'minutes', 'hours', 'rate', 'multiplier', 'amount'])));
        $this->assertSame($request->id, $line['source']['refs'][0]['id']);
        $this->actingAsApi($this->staff)->getJson("/api/v1/payroll-items/{$item->id}")->assertForbidden();
    }

    public function test_void_is_controlled_and_a_voided_request_is_never_paid_again(): void
    {
        $this->worked($this->staffEmployee, '2026-07-14', '20:00');
        $request = $this->overtime($this->staffEmployee, '2026-07-14', 120);
        $july = $this->toReview($this->period());
        $url = "/api/v1/overtime-requests/{$request->id}/void-payroll-inclusion";

        $this->actingAsApi($this->staff)->postJson($url, ['reason' => 'x'])->assertForbidden();
        $this->actingAsApi($this->hr)->postJson($url, ['reason' => 'Paid twice'])->assertStatus(409)->assertJsonPath('error.code', 'PAYROLL_NOT_FINALIZED');

        app(PayrollWorkflow::class)->approve($this->admin, $july);
        app(PayrollWorkflow::class)->finalize($this->admin, $july);
        $this->postJson($url, [])->assertStatus(422);
        $this->postJson($url, ['reason' => 'Overtime was paid outside payroll'])->assertOk()->assertJsonPath('data.payroll_status', 'ADJUSTED');

        $this->assertSame(OvertimeRequest::PAYROLL_ADJUSTED, $request->refresh()->payroll_status);
        $this->assertDatabaseHas('overtime_payroll_inclusions', ['overtime_request_id' => $request->id, 'active_request_id' => null, 'void_reason' => 'Overtime was paid outside payroll']);
        $this->assertDatabaseHas('activities', ['action' => 'overtime.payroll_voided']);

        $august = $this->generate($this->period('2026-08-01', '2026-08-31'));
        $this->assertSame(0, PayrollItemLine::query()->where('source_key', 'overtime')
            ->whereIn('payroll_item_id', PayrollItem::query()->select('id')->where('payroll_run_id', $august->run->id))->count());
    }
}
