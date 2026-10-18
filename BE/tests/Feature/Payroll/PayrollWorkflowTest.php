<?php

namespace Tests\Feature\Payroll;

use App\Enums\PayrollStatus;
use App\Models\Activity;
use App\Models\PayrollPeriod;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PayrollFixtures;
use Tests\Support\WorkplaceFixtures;
use Tests\TestCase;

class PayrollWorkflowTest extends TestCase
{
    use PayrollFixtures, RefreshDatabase, WorkplaceFixtures;

    private const BASE = '/api/v1/payroll-periods';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpWorkplace();
        $this->setUpPayroll();
        $this->at('2026-09-30 10:00');
        [, $e] = $this->makeStaff();
        $this->giveSalary($e, '8000000');
    }

    public function test_full_flow_through_the_api(): void
    {
        $id = $this->actingAsApi($this->hr)->postJson(self::BASE, ['start_date' => '2026-07-01', 'end_date' => '2026-07-31', 'pay_date' => '2026-07-31'])
            ->assertCreated()->assertJsonPath('data.code', '2026-07')->assertJsonPath('data.name', 'July 2026')
            ->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.run.currency', 'IDR')
            ->assertJsonPath('data.run.allowed_actions', ['generate', 'submit', 'cancel'])->json('data.id');

        $this->postJson(self::BASE."/{$id}/generate")->assertOk()->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.run.employee_count', 1)->assertJsonPath('data.run.total_net', '7900000.00');
        $this->postJson(self::BASE."/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'REVIEW');

        $this->actingAsApi($this->admin)->postJson(self::BASE."/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $this->postJson(self::BASE."/{$id}/finalize")->assertOk()->assertJsonPath('data.status', 'FINALIZED')
            ->assertJsonPath('data.run.allowed_actions', ['mark_paid']);
        $this->postJson(self::BASE."/{$id}/mark-paid", ['payment_reference' => 'BATCH-0731'])->assertOk()
            ->assertJsonPath('data.status', 'PAID')->assertJsonPath('data.run.payment_reference', 'BATCH-0731');

        $runId = PayrollPeriod::query()->find($id)->run->id;
        $itemId = $this->getJson("/api/v1/payroll-runs/{$runId}/items")->assertOk()->assertJsonPath('meta.total', 1)->json('data.0.id');
        $this->getJson("/api/v1/payroll-items/{$itemId}")->assertOk()
            ->assertJsonPath('data.earnings.0.code', 'BASIC')->assertJsonPath('data.deductions.0.code', 'COOP');
        $this->getJson(self::BASE.'?status=PAID')->assertOk()->assertJsonPath('meta.total', 1);

        foreach (['payroll_period.created', 'payroll.generated', 'payroll.submitted', 'payroll.approved', 'payroll.finalized', 'payroll.paid'] as $action) {
            $this->assertTrue(Activity::query()->where('action', $action)->exists(), "missing audit {$action}");
        }
        $approved = Activity::query()->where('action', 'payroll.approved')->first();
        $this->assertSame($this->admin->id, $approved->actor_id);
        $this->assertSame(['status' => 'REVIEW'], $approved->old_values);
    }

    public function test_overlapping_regular_periods_are_refused_and_cancelled_ones_free_the_dates(): void
    {
        $period = $this->period();
        $this->actingAsApi($this->hr)->postJson(self::BASE, ['start_date' => '2026-07-15', 'end_date' => '2026-08-14'])
            ->assertStatus(409)->assertJsonPath('error.code', 'PERIOD_OVERLAP');
        $this->postJson(self::BASE, ['start_date' => '2026-08-01', 'end_date' => '2026-10-31'])->assertStatus(422);

        $this->postJson(self::BASE."/{$period->id}/cancel", ['reason' => 'Wrong dates'])->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->postJson(self::BASE, ['start_date' => '2026-07-01', 'end_date' => '2026-07-31'])->assertCreated()->assertJsonPath('data.code', '2026-07-R2');
    }

    public function test_separate_approver_rule(): void
    {
        $period = $this->toReview($this->period());

        // HR generated + submitted: HR (who holds payroll.approve) cannot approve their own payroll.
        $this->actingAsApi($this->hr)->postJson(self::BASE."/{$period->id}/approve")
            ->assertStatus(403)->assertJsonPath('error.code', 'SEPARATE_APPROVER_REQUIRED');
        $this->assertSame('REVIEW', $period->fresh()->status->value);

        app(Settings::class)->set(['payroll.require_separate_approver' => false]);
        $this->postJson(self::BASE."/{$period->id}/approve")->assertOk()->assertJsonPath('data.status', 'APPROVED');
    }

    public function test_negative_net_blocks_approval_with_a_clear_error(): void
    {
        [, $e] = $this->makeStaff();
        $this->giveSalary($e, '50000', '2026-01-01', ['COOP' => '100000']);
        $period = $this->toReview($this->period());
        $this->assertSame(1, $period->run->negative_net_count);

        $this->actingAsApi($this->admin)->postJson(self::BASE."/{$period->id}/approve")
            ->assertStatus(422)->assertJsonPath('error.code', 'NEGATIVE_NET_PAY')
            ->assertJsonPath('error.details.employees.0.employee_id', $e->id)
            ->assertJsonPath('error.details.employees.0.net_pay', '-50000.00');
        $this->assertSame('REVIEW', $period->fresh()->status->value);
    }

    public function test_return_to_draft_requires_a_reason_and_clears_the_submission(): void
    {
        $period = $this->toReview($this->period());
        $this->actingAsApi($this->admin)->postJson(self::BASE."/{$period->id}/return-to-draft")->assertStatus(422);
        $this->postJson(self::BASE."/{$period->id}/return-to-draft", ['reason' => 'Overtime missing'])
            ->assertOk()->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.run.return_reason', 'Overtime missing')
            ->assertJsonPath('data.run.submitted_by', null);
        $this->assertTrue(Activity::query()->where('action', 'payroll.returned')->exists());
    }

    public function test_every_invalid_action_in_every_state_is_409(): void
    {
        $period = $this->toReview($this->period());
        $actions = [
            'generate' => ['DRAFT', 'REVIEW'],
            'submit' => ['DRAFT'],
            'approve' => ['REVIEW'],
            'return-to-draft' => ['REVIEW', 'APPROVED'],
            'finalize' => ['APPROVED'],
            'mark-paid' => ['FINALIZED'],
            'cancel' => ['DRAFT', 'REVIEW', 'APPROVED'],
        ];
        $body = ['reason' => 'Testing transitions'];
        $checked = 0;

        foreach (PayrollStatus::cases() as $state) {
            DB::table('payroll_runs')->where('id', $period->run->id)->update(['status' => $state->value, 'processing_started_at' => now()]);
            DB::table('payroll_periods')->where('id', $period->id)->update(['status' => $state->value]);

            foreach ($actions as $action => $allowedFrom) {
                if (in_array($state->value, $allowedFrom, true)) {
                    continue;
                }
                $expected = $action === 'generate' && $state === PayrollStatus::Processing ? 'PAYROLL_PROCESSING' : 'INVALID_STATE_TRANSITION';
                $this->actingAsApi($this->admin)->postJson(self::BASE."/{$period->id}/{$action}", $body)
                    ->assertStatus(409)->assertJsonPath('error.code', $expected);
                $this->assertSame($state->value, DB::table('payroll_runs')->where('id', $period->run->id)->value('status'));
                $checked++;
            }
        }

        $this->assertSame(7 * 7 - 11, $checked);
    }

    public function test_permissions_per_action(): void
    {
        [$employee] = $this->makeStaff('employee');
        [$manager] = $this->makeStaff('manager');
        $period = $this->period();

        foreach ([$employee, $manager] as $user) {
            $this->actingAsApi($user)->getJson(self::BASE)->assertForbidden();
            $this->getJson(self::BASE."/{$period->id}")->assertForbidden();
            $this->postJson(self::BASE, ['start_date' => '2026-08-01', 'end_date' => '2026-08-31'])->assertForbidden();
            $this->postJson(self::BASE."/{$period->id}/generate")->assertForbidden();
            $this->getJson('/api/v1/salary-components')->assertForbidden();
        }

        // A read-only payroll viewer (custom role) can read but not act.
        [$viewer] = $this->makeStaff('employee');
        $role = Role::query()->create(['slug' => 'payroll-viewer', 'name' => 'Payroll viewer']);
        $role->permissions()->attach(Permission::query()->where('slug', 'payroll.view')->value('id'));
        $viewer->assignRole('payroll-viewer');
        $this->actingAsApi($viewer)->getJson(self::BASE."/{$period->id}")->assertOk();
        $this->postJson(self::BASE."/{$period->id}/generate")->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
        $this->assertSame('DRAFT', $period->fresh()->status->value);
    }
}
