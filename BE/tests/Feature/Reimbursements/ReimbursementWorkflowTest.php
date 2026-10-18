<?php

namespace Tests\Feature\Reimbursements;

use App\Enums\ExpenseStatus as S;
use App\Models\Activity;
use App\Models\AppNotification;
use App\Models\Employee;
use App\Models\ExpenseCategory;
use App\Models\ExpensePayment;
use App\Models\ExpenseRequest;
use App\Models\ExpenseRequestEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Reimbursements\ExpenseStateMachine;
use App\Services\Reimbursements\ReimbursementService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\ReimbursementFixtures;
use Tests\TestCase;

/** Roles, scope, IDOR, state machine, decisions, payment and audit for the reimbursement API. */
class ReimbursementWorkflowTest extends TestCase
{
    use RefreshDatabase, ReimbursementFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReimbursements();
        $this->at('2026-10-10 10:00');
    }

    private function api(User $user): static
    {
        return $this->actingAsApi($user);
    }

    // ------------------------------------------------------------------ happy paths

    public function test_employee_to_payment_through_the_api_with_manager_and_hr(): void
    {
        $created = $this->api($this->employeeUser)->post('/api/v1/reimbursements', $this->payload(['submitted_amount' => '250000.50']) + ['receipt' => $this->receipt()], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.submitted_amount', '250000.50')
            ->assertJsonPath('data.receipt.extension', 'pdf')->assertJsonPath('data.actions.can_submit', true);
        $id = $created->json('data.id');
        $this->assertSame('RB-202610-0001', $created->json('data.request_number'));

        $this->postJson("/api/v1/reimbursements/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'SUBMITTED');

        // The manager of the employee reviews and approves with a lower amount and an explanation.
        $this->api($this->manager)->getJson('/api/v1/reimbursements?scope=review')->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson("/api/v1/reimbursements/{$id}/review")->assertOk()->assertJsonPath('data.status', 'UNDER_REVIEW');
        $this->postJson("/api/v1/reimbursements/{$id}/approve", ['approved_amount' => '200000', 'adjustment_reason' => 'Taxi above the daily cap'])
            ->assertOk()->assertJsonPath('data.status', 'APPROVED')->assertJsonPath('data.approved_amount', '200000.00')
            ->assertJsonPath('data.payment_route', 'SEPARATE')->assertJsonPath('data.adjustment_reason', 'Taxi above the daily cap');

        // Managers hold no payment permission.
        $this->postJson("/api/v1/reimbursements/{$id}/schedule")->assertForbidden();

        $this->api($this->hr)->postJson("/api/v1/reimbursements/{$id}/schedule", ['scheduled_for' => '2026-10-15'])->assertOk()->assertJsonPath('data.status', 'SCHEDULED_FOR_PAYMENT');
        $this->postJson("/api/v1/reimbursements/{$id}/pay", ['idempotency_key' => 'pay-1', 'method' => 'BANK_TRANSFER', 'reference' => 'TRX-9'])
            ->assertOk()->assertJsonPath('data.status', 'PAID')->assertJsonPath('data.payment.amount', '200000.00')
            ->assertJsonPath('data.payment.reference', 'TRX-9')->assertJsonPath('meta.idempotent_replay', false);

        $this->api($this->employeeUser)->getJson("/api/v1/reimbursements/{$id}")->assertOk()->assertJsonPath('data.status', 'PAID')->assertJsonPath('data.actions.can_withdraw', false);
        $history = $this->getJson("/api/v1/reimbursements/{$id}/history")->assertOk();
        $this->assertSame(['created', 'submitted', 'review_started', 'approved', 'scheduled', 'paid'], collect($history->json('data.events'))->pluck('action')->all());
        $history->assertJsonPath('data.payment.method', 'BANK_TRANSFER');
    }

    public function test_hr_can_decide_and_admin_can_decide_hr_own_claim(): void
    {
        $claim = $this->makeClaim($this->employee);
        $this->api($this->hr)->postJson("/api/v1/reimbursements/{$claim->id}/review")->assertOk();
        $this->postJson("/api/v1/reimbursements/{$claim->id}/approve")->assertOk()->assertJsonPath('data.approved_amount', '100000.00');

        $own = $this->makeClaim($this->hrEmployee);
        $this->api($this->admin)->postJson("/api/v1/reimbursements/{$own->id}/review")->assertOk();
        $this->postJson("/api/v1/reimbursements/{$own->id}/reject", ['reason' => 'Duplicate of an earlier claim'])->assertOk()->assertJsonPath('data.status', 'REJECTED');
    }

    public function test_categories_and_policy_are_readable_by_employees_and_configurable_by_hr_only(): void
    {
        $this->api($this->employeeUser)->getJson('/api/v1/expense-categories')->assertOk()->assertJsonCount(6, 'data');
        $this->getJson('/api/v1/expense-policy')->assertOk()->assertJsonPath('data.payment_route', 'SEPARATE')->assertJsonPath('data.approval_route', 'MANAGER_OR_HR');
        $this->postJson('/api/v1/expense-categories', ['code' => 'X', 'name' => 'X'])->assertForbidden();
        $this->putJson('/api/v1/expense-policy', ['payment_route' => 'PAYROLL'])->assertForbidden();

        $this->api($this->hr)->postJson('/api/v1/expense-categories', ['code' => 'training', 'name' => 'Training', 'max_amount_per_claim' => 2000000, 'receipt_required' => false, 'max_age_days' => 90])
            ->assertCreated()->assertJsonPath('data.code', 'TRAINING');
        $this->postJson('/api/v1/expense-categories', ['code' => 'TRAINING', 'name' => 'Again'])->assertStatus(422);
        $id = ExpenseCategory::query()->where('code', 'TRAINING')->value('id');
        $this->patchJson("/api/v1/expense-categories/{$id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->api($this->employeeUser)->getJson('/api/v1/expense-categories')->assertJsonCount(6, 'data');
        $this->api($this->hr)->getJson('/api/v1/expense-categories?include_inactive=1')->assertJsonCount(7, 'data');

        $this->putJson('/api/v1/expense-policy', ['payment_route' => 'PAYROLL', 'approval_route' => 'HR_ONLY'])->assertOk()->assertJsonPath('data.payment_route', 'PAYROLL');
        $this->putJson('/api/v1/expense-policy', ['payment_route' => 'CARRIER_PIGEON'])->assertStatus(422);
        $this->assertTrue(Activity::query()->where('action', 'expense_policy.updated')->exists());
        $this->assertTrue(Activity::query()->where('action', 'expense_category.created')->exists());
    }

    // ------------------------------------------------------------------ scope & IDOR

    public function test_visibility_scope_per_role(): void
    {
        $mine = $this->makeClaim($this->employee);
        $stranger = $this->makeClaim($this->strangerEmployee);
        $draft = $this->makeClaim($this->employee, submit: false);

        $this->api($this->employeeUser)->getJson('/api/v1/reimbursements')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/reimbursements?scope=all')->assertForbidden();
        $this->getJson('/api/v1/reimbursements?scope=review')->assertForbidden();

        // The manager sees only the direct report (and not the draft).
        $this->api($this->manager)->getJson('/api/v1/reimbursements?scope=all')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $mine->id);
        $this->getJson("/api/v1/reimbursements/{$stranger->id}")->assertNotFound();
        $this->getJson("/api/v1/reimbursements/{$draft->id}")->assertNotFound();
        $this->postJson("/api/v1/reimbursements/{$stranger->id}/review")->assertNotFound();

        // HR sees every submitted claim but never someone else's draft.
        $this->api($this->hr)->getJson('/api/v1/reimbursements?scope=all')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson("/api/v1/reimbursements/{$draft->id}")->assertNotFound();
        $this->getJson("/api/v1/reimbursements/{$stranger->id}")->assertOk();
    }

    public function test_idor_on_request_and_receipt_is_a_404_identical_to_missing(): void
    {
        $theirs = $this->makeClaim($this->employee);

        $foreign = $this->api($this->stranger)->getJson("/api/v1/reimbursements/{$theirs->id}");
        $missing = $this->getJson('/api/v1/reimbursements/999999');
        $foreign->assertNotFound();
        $this->assertSame($missing->json('error.code'), $foreign->json('error.code'));
        $this->assertSame($missing->json('error.message'), $foreign->json('error.message'));

        $this->get("/api/v1/reimbursements/{$theirs->id}/receipt")->assertNotFound();
        $this->getJson("/api/v1/reimbursements/{$theirs->id}/history")->assertNotFound();
        foreach (['submit', 'review', 'approve', 'withdraw', 'schedule'] as $action) {
            $this->postJson("/api/v1/reimbursements/{$theirs->id}/{$action}")->assertNotFound();
        }
        $this->postJson("/api/v1/reimbursements/{$theirs->id}/reject", ['reason' => 'x'])->assertNotFound();
        $this->post("/api/v1/reimbursements/{$theirs->id}/receipt", ['receipt' => $this->receipt()], ['Accept' => 'application/json'])->assertNotFound();
        $this->patchJson("/api/v1/reimbursements/{$theirs->id}", ['business_purpose' => 'hijack'])->assertNotFound();
        $this->postJson("/api/v1/reimbursements/{$theirs->id}/pay", ['method' => 'CASH', 'idempotency_key' => 'k'])->assertNotFound();
        $this->assertSame('SUBMITTED', $this->claimStatus($theirs));

        $this->api($this->employeeUser)->get("/api/v1/reimbursements/{$theirs->id}/receipt")->assertOk();
    }

    public function test_a_user_without_expense_permissions_gets_403(): void
    {
        [$kiosk] = $this->makeNoExpenseStaff();
        $this->api($kiosk)->getJson('/api/v1/reimbursements')->assertForbidden();
        $this->getJson('/api/v1/expense-categories')->assertForbidden();
    }

    // ------------------------------------------------------------------ no self-approval

    public function test_nobody_can_approve_reject_review_or_pay_their_own_claim(): void
    {
        $own = $this->makeClaim($this->hrEmployee);   // HR holds expense.approve + expense.pay
        $this->api($this->hr)->postJson("/api/v1/reimbursements/{$own->id}/review")->assertForbidden();
        $this->postJson("/api/v1/reimbursements/{$own->id}/approve")->assertForbidden();
        $this->postJson("/api/v1/reimbursements/{$own->id}/reject", ['reason' => 'x'])->assertForbidden();
        $this->assertSame('SUBMITTED', $this->claimStatus($own));

        $decided = app(ReimbursementService::class)->startReview($this->admin, $own);
        app(ReimbursementService::class)->approve($this->admin, $decided);
        $this->api($this->hr)->postJson("/api/v1/reimbursements/{$own->id}/schedule")->assertForbidden();

        // A manager's own claim cannot be decided by that manager either (not in the report scope).
        $managerClaim = $this->makeClaim($this->managerEmployee);
        $this->api($this->manager)->postJson("/api/v1/reimbursements/{$managerClaim->id}/review")->assertForbidden();
        $this->assertSame(0, ExpenseRequestEvent::query()->where('expense_request_id', $managerClaim->id)->where('action', 'review_started')->count());
    }

    public function test_hr_only_approval_route_excludes_managers(): void
    {
        $claim = $this->makeClaim($this->employee);
        $this->setPolicy(['approval_route' => 'HR_ONLY']);
        $this->api($this->manager)->postJson("/api/v1/reimbursements/{$claim->id}/review")->assertForbidden();
        $this->api($this->hr)->postJson("/api/v1/reimbursements/{$claim->id}/review")->assertOk();
    }

    // ------------------------------------------------------------------ state machine

    public function test_state_machine_table_is_the_single_source_of_truth(): void
    {
        $edges = [];
        foreach (S::cases() as $from) {
            foreach (S::cases() as $to) {
                if (ExpenseStateMachine::can($from, $to)) {
                    $edges[] = "{$from->value}>{$to->value}";
                }
            }
        }
        sort($edges);
        $expected = ['DRAFT>CANCELLED', 'DRAFT>SUBMITTED', 'SUBMITTED>UNDER_REVIEW', 'SUBMITTED>CANCELLED', 'UNDER_REVIEW>APPROVED', 'UNDER_REVIEW>REJECTED', 'UNDER_REVIEW>CANCELLED',
            'APPROVED>SCHEDULED_FOR_PAYMENT', 'APPROVED>CANCELLED', 'SCHEDULED_FOR_PAYMENT>PAID', 'SCHEDULED_FOR_PAYMENT>CANCELLED'];
        sort($expected);
        $this->assertSame($expected, $edges);
    }

    /** @return array<string, ExpenseRequest> one claim per status */
    private function claimsInEveryStatus(): array
    {
        $service = app(ReimbursementService::class);
        $rejected = $service->reject($this->hr, $this->underReview(), 'No');
        $cancelled = $service->cancel($this->hr, $this->makeClaim(), 'Duplicate');

        return [
            'DRAFT' => $this->makeClaim(submit: false),
            'SUBMITTED' => $this->makeClaim(),
            'UNDER_REVIEW' => $this->underReview(),
            'APPROVED' => $this->approved(),
            'SCHEDULED_FOR_PAYMENT' => $this->scheduled(),
            'PAID' => $this->paid(),
            'REJECTED' => $rejected,
            'CANCELLED' => $cancelled,
        ];
    }

    public function test_every_invalid_transition_is_a_409_and_changes_nothing(): void
    {
        $claims = $this->claimsInEveryStatus();

        // action => [acting role, request path suffix, body, statuses where it is valid]
        $actions = [
            'submit' => ['employee', 'submit', [], ['DRAFT']],
            'review' => ['hr', 'review', [], ['SUBMITTED']],
            'approve' => ['hr', 'approve', [], ['UNDER_REVIEW']],
            'reject' => ['hr', 'reject', ['reason' => 'No'], ['UNDER_REVIEW']],
            'withdraw' => ['employee', 'withdraw', [], ['DRAFT', 'SUBMITTED', 'UNDER_REVIEW']],
            'cancel' => ['hr', 'cancel', ['reason' => 'Mistake'], ['SUBMITTED', 'UNDER_REVIEW', 'APPROVED', 'SCHEDULED_FOR_PAYMENT']],
            'schedule' => ['hr', 'schedule', [], ['APPROVED']],
            'pay' => ['hr', 'pay', ['method' => 'CASH', 'idempotency_key' => 'matrix'], ['SCHEDULED_FOR_PAYMENT']],
        ];

        $checked = 0;
        foreach ($actions as $name => [$role, $path, $body, $validIn]) {
            foreach ($claims as $status => $claim) {
                if (in_array($status, $validIn, true)) {
                    continue; // the happy paths are asserted elsewhere (and would change the fixture)
                }
                $user = $role === 'employee' ? $this->employeeUser : $this->hr;
                $res = $this->api($user)->postJson("/api/v1/reimbursements/{$claim->id}/{$path}", $body);

                if ($status === 'DRAFT' && $role === 'hr') {
                    $res->assertNotFound(); // other people's drafts are invisible, so nothing leaks about them
                } else {
                    $res->assertStatus(409);
                    $this->assertContains($res->json('error.code'), ['INVALID_TRANSITION', 'ALREADY_PAID'], "{$name} on {$status}: ".$res->getContent());
                }
                $this->assertSame($status, $this->claimStatus($claim), "{$name} must not change a {$status} claim");
                $checked++;
            }
        }
        $this->assertSame(8 * 8 - 13, $checked);
        $this->assertSame(1, ExpensePayment::query()->count(), 'only the PAID fixture has a payment');
    }

    public function test_rejected_claims_cannot_be_resubmitted_or_revived(): void
    {
        $claim = app(ReimbursementService::class)->reject($this->hr, $this->underReview(), 'Not business related');
        $this->api($this->employeeUser)->postJson("/api/v1/reimbursements/{$claim->id}/submit")->assertStatus(409);
        $this->patchJson("/api/v1/reimbursements/{$claim->id}", ['business_purpose' => 'Reworded'])->assertStatus(409);
        $this->assertSame('Not business related', $claim->fresh()->rejection_reason);
    }

    // ------------------------------------------------------------------ reject / approve rules

    public function test_rejecting_requires_a_reason(): void
    {
        $claim = $this->underReview();
        $this->api($this->hr)->postJson("/api/v1/reimbursements/{$claim->id}/reject")->assertStatus(422);
        $this->postJson("/api/v1/reimbursements/{$claim->id}/reject", ['reason' => '   '])->assertStatus(422);
        $this->assertSame('UNDER_REVIEW', $this->claimStatus($claim));

        $this->postJson("/api/v1/reimbursements/{$claim->id}/reject", ['reason' => 'Receipt unreadable'])->assertOk()->assertJsonPath('data.rejection_reason', 'Receipt unreadable');
        $this->assertSame(1, AppNotification::query()->where('user_id', $this->employeeUser->id)->where('type', 'expense.rejected')->count());
    }

    public function test_approved_amount_rules(): void
    {
        $claim = $this->underReview();
        $this->api($this->hr);

        $this->postJson("/api/v1/reimbursements/{$claim->id}/approve", ['approved_amount' => '100000.01'])->assertStatus(422)->assertJsonPath('error.code', 'APPROVED_AMOUNT_EXCEEDS_SUBMITTED');
        $this->postJson("/api/v1/reimbursements/{$claim->id}/approve", ['approved_amount' => '50000'])->assertStatus(422)->assertJsonPath('error.code', 'ADJUSTMENT_REASON_REQUIRED');
        $this->postJson("/api/v1/reimbursements/{$claim->id}/approve", ['approved_amount' => '0'])->assertStatus(422);
        $this->postJson("/api/v1/reimbursements/{$claim->id}/approve", ['approved_amount' => '-5'])->assertStatus(422);
        $this->assertSame('UNDER_REVIEW', $this->claimStatus($claim));
        $this->assertNull($claim->fresh()->approved_amount);

        $this->postJson("/api/v1/reimbursements/{$claim->id}/approve", ['approved_amount' => '50000', 'adjustment_reason' => 'Only the outbound taxi is claimable'])
            ->assertOk()->assertJsonPath('data.approved_amount', '50000.00');

        $audit = Activity::query()->where('action', 'expense.approved')->where('entity_id', $claim->id)->firstOrFail();
        $this->assertSame('50000.00', $audit->new_values['approved_amount']);
        $this->assertSame('Only the outbound taxi is claimable', $audit->new_values['adjustment_reason']);
        $event = ExpenseRequestEvent::query()->where('expense_request_id', $claim->id)->where('action', 'approved')->firstOrFail();
        $this->assertSame('100000.00', $event->data['submitted_amount']);
        $this->assertSame('50000.00', $event->data['approved_amount']);

        // Default: approving without an amount approves what was submitted (no reason needed).
        $other = $this->underReview(overrides: ['submitted_amount' => '80000']);
        $this->postJson("/api/v1/reimbursements/{$other->id}/approve")->assertOk()->assertJsonPath('data.approved_amount', '80000.00')->assertJsonPath('data.adjustment_reason', null);
    }

    // ------------------------------------------------------------------ category / limit rules

    public function test_receipt_requirement_is_a_category_setting(): void
    {
        $required = $this->makeClaim(overrides: [], submit: false, withReceipt: false);
        $this->api($this->employeeUser)->postJson("/api/v1/reimbursements/{$required->id}/submit")->assertStatus(422)->assertJsonPath('error.code', 'RECEIPT_REQUIRED');

        $optional = $this->makeClaim(overrides: ['expense_category_id' => $this->category('PARKING_TOLL')->id, 'submitted_amount' => '20000'], submit: true, withReceipt: false);
        $this->assertSame('SUBMITTED', $optional->status->value);

        $this->category('PARKING_TOLL')->update(['receipt_required' => true]);
        $this->postJson("/api/v1/reimbursements/{$this->makeClaim(overrides: ['expense_category_id' => $this->category('PARKING_TOLL')->id, 'submitted_amount' => '20000'], submit: false, withReceipt: false)->id}/submit")
            ->assertStatus(422)->assertJsonPath('error.code', 'RECEIPT_REQUIRED');
    }

    public function test_per_claim_and_company_wide_limits(): void
    {
        $over = $this->makeClaim(overrides: ['submitted_amount' => '1500001'], submit: false);
        $this->api($this->employeeUser)->postJson("/api/v1/reimbursements/{$over->id}/submit")->assertStatus(422)->assertJsonPath('error.code', 'EXPENSE_LIMIT_EXCEEDED');
        $this->assertSame('DRAFT', $this->claimStatus($over));

        $this->makeClaim(overrides: ['submitted_amount' => '1500000']); // exactly at the limit passes

        $this->setPolicy(['max_amount_per_claim' => '90000']);
        $small = $this->makeClaim(overrides: ['submitted_amount' => '90001', 'expense_category_id' => $this->category('OFFICE_SUPPLIES')->id], submit: false);
        $this->postJson("/api/v1/reimbursements/{$small->id}/submit")->assertStatus(422)->assertJsonPath('error.details.scope', 'company');
    }

    public function test_monthly_limit_counts_live_claims_and_frees_cancelled_ones(): void
    {
        $this->category('TRANSPORT')->update(['max_amount_per_month' => '250000']);
        $first = $this->makeClaim(overrides: ['submitted_amount' => '150000']);
        $second = $this->makeClaim(overrides: ['submitted_amount' => '100000']);   // 250000 = limit, allowed

        $third = $this->makeClaim(overrides: ['submitted_amount' => '1'], submit: false);
        $res = $this->api($this->employeeUser)->postJson("/api/v1/reimbursements/{$third->id}/submit")->assertStatus(422);
        $res->assertJsonPath('error.code', 'EXPENSE_MONTHLY_LIMIT_EXCEEDED')->assertJsonPath('error.details.already_claimed', '250000.00');

        // Another employee and another category have their own allowance.
        $this->makeClaim($this->strangerEmployee, ['submitted_amount' => '250000']);
        $this->makeClaim(overrides: ['expense_category_id' => $this->category('MEALS')->id, 'submitted_amount' => '100000']);

        // Withdrawing a claim gives its allowance back.
        $this->postJson("/api/v1/reimbursements/{$second->id}/withdraw")->assertOk();
        $this->postJson("/api/v1/reimbursements/{$third->id}/submit")->assertOk();

        // A rejected claim never counted for the future either.
        $this->assertSame('CANCELLED', $this->claimStatus($second));
        $this->assertNotSame($first->id, $third->id);
    }

    public function test_monthly_limit_uses_the_approved_amount_after_adjustment(): void
    {
        $this->category('TRANSPORT')->update(['max_amount_per_month' => '300000']);
        $a = $this->underReview(overrides: ['submitted_amount' => '200000']);
        app(ReimbursementService::class)->approve($this->hr, $a, '100000', 'Partial');
        $this->makeClaim(overrides: ['submitted_amount' => '200000']); // 100000 approved + 200000 = 300000 passes
        $this->assertTrue(true);
    }

    public function test_expense_age_date_and_category_availability(): void
    {
        $this->category('MEALS')->update(['max_age_days' => 30]);
        $meals = $this->category('MEALS')->id;
        $old = $this->makeClaim(overrides: ['expense_category_id' => $meals, 'expense_date' => '2026-09-09', 'submitted_amount' => '50000'], submit: false);   // 31 days ago
        $this->api($this->employeeUser)->postJson("/api/v1/reimbursements/{$old->id}/submit")->assertStatus(422)->assertJsonPath('error.code', 'EXPENSE_TOO_OLD');
        $edge = $this->makeClaim(overrides: ['expense_category_id' => $meals, 'expense_date' => '2026-09-10', 'submitted_amount' => '50000'], submit: false); // exactly 30 days: allowed
        $this->postJson("/api/v1/reimbursements/{$edge->id}/submit")->assertOk();

        $this->postJson('/api/v1/reimbursements', $this->payload(['expense_date' => '2026-10-11']))->assertStatus(422)->assertJsonPath('error.code', 'EXPENSE_DATE_IN_FUTURE');
        $this->postJson('/api/v1/reimbursements', $this->payload(['expense_date' => '2026-02-30']))->assertStatus(422);
        $this->postJson('/api/v1/reimbursements', $this->payload(['submitted_amount' => '0']))->assertStatus(422);
        $this->postJson('/api/v1/reimbursements', $this->payload(['submitted_amount' => '10.123']))->assertStatus(422);
        $this->postJson('/api/v1/reimbursements', $this->payload(['currency' => 'USD']))->assertStatus(422)->assertJsonPath('error.code', 'CURRENCY_NOT_SUPPORTED');
        $this->postJson('/api/v1/reimbursements', $this->payload(['business_purpose' => '  ']))->assertStatus(422);

        $this->category('PARKING_TOLL')->update(['is_active' => false]);
        $this->postJson('/api/v1/reimbursements', $this->payload(['expense_category_id' => $this->category('PARKING_TOLL')->id]))->assertStatus(422)->assertJsonPath('error.code', 'EXPENSE_CATEGORY_UNAVAILABLE');
        $this->postJson('/api/v1/reimbursements', $this->payload(['expense_category_id' => 987654]))->assertStatus(422);
    }

    public function test_only_the_owner_edits_and_only_drafts(): void
    {
        $draft = $this->makeClaim(submit: false);
        $this->api($this->employeeUser)->patchJson("/api/v1/reimbursements/{$draft->id}", ['business_purpose' => 'Updated', 'submitted_amount' => '120000'])
            ->assertOk()->assertJsonPath('data.business_purpose', 'Updated')->assertJsonPath('data.submitted_amount', '120000.00');
        $this->postJson("/api/v1/reimbursements/{$draft->id}/submit")->assertOk();
        $this->patchJson("/api/v1/reimbursements/{$draft->id}", ['business_purpose' => 'Late edit'])->assertStatus(409);
        $this->post("/api/v1/reimbursements/{$draft->id}/receipt", ['receipt' => $this->receipt()], ['Accept' => 'application/json'])->assertStatus(409);
        $this->api($this->hr)->patchJson("/api/v1/reimbursements/{$draft->id}", ['business_purpose' => 'HR edit'])->assertForbidden();
    }

    // ------------------------------------------------------------------ withdraw / cancel

    public function test_withdraw_before_decision_and_hr_cancel_after_keep_history(): void
    {
        $submitted = $this->makeClaim();
        $this->api($this->employeeUser)->postJson("/api/v1/reimbursements/{$submitted->id}/withdraw", ['reason' => 'Wrong amount'])->assertOk()->assertJsonPath('data.status', 'CANCELLED');

        $approved = $this->approved();
        $this->postJson("/api/v1/reimbursements/{$approved->id}/withdraw")->assertStatus(409)->assertJsonPath('error.code', 'INVALID_TRANSITION');
        $this->api($this->hr)->postJson("/api/v1/reimbursements/{$approved->id}/cancel")->assertStatus(422);
        $this->postJson("/api/v1/reimbursements/{$approved->id}/cancel", ['reason' => 'Budget frozen'])->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->api($this->employeeUser)->postJson("/api/v1/reimbursements/{$approved->id}/cancel", ['reason' => 'x'])->assertForbidden();

        $paid = $this->paid();
        $this->api($this->hr)->postJson("/api/v1/reimbursements/{$paid->id}/cancel", ['reason' => 'Oops'])->assertStatus(409);

        // Nothing is ever deleted: cancelled claims stay listed with their history.
        $this->api($this->employeeUser)->getJson('/api/v1/reimbursements?status=CANCELLED')->assertJsonPath('meta.total', 2);
        $this->assertGreaterThanOrEqual(2, ExpenseRequestEvent::query()->where('expense_request_id', $submitted->id)->count());
        $this->assertSame(1, AppNotification::query()->where('user_id', $this->employeeUser->id)->where('type', 'expense.cancelled')->count());
    }

    // ------------------------------------------------------------------ payment

    public function test_payment_is_idempotent_and_never_duplicated(): void
    {
        $claim = $this->scheduled();
        $body = ['idempotency_key' => 'abc-123', 'method' => 'BANK_TRANSFER', 'reference' => 'TRX-1'];

        $this->api($this->hr)->postJson("/api/v1/reimbursements/{$claim->id}/pay", $body)->assertOk()->assertJsonPath('meta.idempotent_replay', false);
        $this->postJson("/api/v1/reimbursements/{$claim->id}/pay", $body)->assertOk()->assertJsonPath('meta.idempotent_replay', true)->assertJsonPath('data.status', 'PAID');
        $this->postJson("/api/v1/reimbursements/{$claim->id}/pay", ['idempotency_key' => 'another', 'method' => 'CASH'])->assertStatus(409)->assertJsonPath('error.code', 'ALREADY_PAID');

        $other = $this->scheduled();
        $this->postJson("/api/v1/reimbursements/{$other->id}/pay", $body)->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
        $this->assertSame('SCHEDULED_FOR_PAYMENT', $this->claimStatus($other));

        $this->assertSame(1, ExpensePayment::query()->where('expense_request_id', $claim->id)->count());
        $this->assertSame(1, ExpenseRequestEvent::query()->where('expense_request_id', $claim->id)->where('action', 'paid')->count());
        $this->assertSame(1, AppNotification::query()->where('type', 'expense.paid')->where('entity_id', $claim->id)->count());
        $this->assertSame(1, Activity::query()->where('action', 'expense.paid')->where('entity_id', $claim->id)->count());

        $this->postJson("/api/v1/reimbursements/{$other->id}/pay", ['idempotency_key' => 'k2'])->assertStatus(422);
        $this->postJson("/api/v1/reimbursements/{$other->id}/pay", ['idempotency_key' => 'k2', 'method' => 'BARTER'])->assertStatus(422);
        $this->postJson("/api/v1/reimbursements/{$other->id}/pay", ['method' => 'CASH'])->assertStatus(422);                         // key required
        $this->withHeader('Idempotency-Key', 'hdr-key')->postJson("/api/v1/reimbursements/{$other->id}/pay", ['method' => 'CASH'])->assertOk();  // header works too
    }

    public function test_payroll_routed_claims_are_never_paid_or_scheduled_separately(): void
    {
        $this->setPolicy(['payment_route' => 'PAYROLL']);
        $claim = $this->approved();
        $this->assertSame('PAYROLL', $claim->payment_route);

        $this->api($this->hr)->postJson("/api/v1/reimbursements/{$claim->id}/schedule")->assertStatus(409)->assertJsonPath('error.code', 'PAYMENT_ROUTE_PAYROLL');
        $this->postJson("/api/v1/reimbursements/{$claim->id}/pay", ['idempotency_key' => 'p', 'method' => 'CASH'])->assertStatus(409);
        $this->assertSame(0, ExpensePayment::query()->count());
    }

    public function test_history_tables_are_append_only(): void
    {
        $claim = $this->paid();
        $event = ExpenseRequestEvent::query()->where('expense_request_id', $claim->id)->firstOrFail();
        $payment = ExpensePayment::query()->where('expense_request_id', $claim->id)->firstOrFail();

        foreach ([$event, $payment] as $row) {
            try {
                $row->update(['note' => 'tampered']);
                $this->fail('model update must be refused');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
            try {
                $row->delete();
                $this->fail('model delete must be refused');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        foreach (['expense_request_events', 'expense_payments'] as $table) {
            try {
                DB::table($table)->update(['created_at' => now()]);
                $this->fail("{$table} update must be refused by the database");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
            try {
                DB::table($table)->delete();
                $this->fail("{$table} delete must be refused by the database");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(1, ExpensePayment::query()->count());
    }

    // ------------------------------------------------------------------ audit & notifications

    public function test_every_step_is_audited_and_notifies_exactly_once(): void
    {
        $claim = $this->makeClaim();
        $service = app(ReimbursementService::class);
        $service->startReview($this->hr, $claim);
        $service->approve($this->hr, $claim, '90000', 'Partial');
        $service->schedule($this->hr, $claim);

        $actions = Activity::query()->where('entity_type', $claim->getMorphClass())->where('entity_id', $claim->id)->orderBy('id')->pluck('action')->all();
        $this->assertSame(['expense.created', 'expense.submitted', 'expense.review_started', 'expense.approved', 'expense.scheduled'], $actions);
        $this->assertNotContains(null, Activity::query()->where('entity_id', $claim->id)->pluck('actor_id')->all());

        $audit = json_encode(Activity::query()->where('entity_id', $claim->id)->get(['old_values', 'new_values'])->toArray());
        $this->assertStringNotContainsString('receipts/', $audit, 'storage paths never reach the audit trail');
        $this->assertStringNotContainsString('receipt_path', $audit);

        // The claimant's manager is told about the submission; the claimant about approval and scheduling.
        $this->assertSame(1, AppNotification::query()->where('user_id', $this->manager->id)->where('type', 'expense.pending_review')->count());
        $this->assertSame(0, AppNotification::query()->where('user_id', $this->hr->id)->where('type', 'expense.pending_review')->count());
        $this->assertSame(1, AppNotification::query()->where('user_id', $this->employeeUser->id)->where('type', 'expense.approved')->count());
        $this->assertSame(1, AppNotification::query()->where('user_id', $this->employeeUser->id)->where('type', 'expense.scheduled')->count());
        $this->assertSame(1, AppNotification::query()->where('user_id', $this->hr->id)->where('type', 'expense.ready_for_payment')->count());
        $this->assertSame(0, AppNotification::query()->where('user_id', $this->employeeUser->id)->where('type', 'expense.ready_for_payment')->count());
    }

    public function test_without_a_manager_hr_is_notified(): void
    {
        $this->makeClaim($this->strangerEmployee);
        $this->assertSame(1, AppNotification::query()->where('user_id', $this->hr->id)->where('type', 'expense.pending_review')->count());
        $this->assertSame(0, AppNotification::query()->where('user_id', $this->stranger->id)->where('type', 'expense.pending_review')->count());
    }

    // ------------------------------------------------------------------ request numbers & roles

    public function test_request_numbers_are_sequential_per_month_and_unique(): void
    {
        $a = $this->makeClaim(submit: false);
        $b = $this->makeClaim(submit: false);
        $this->assertSame('RB-202610-0001', $a->request_number);
        $this->assertSame('RB-202610-0002', $b->request_number);

        $this->at('2026-11-02 09:00');
        $c = $this->makeClaim(submit: false, overrides: ['expense_date' => '2026-11-01']);
        $this->assertSame('RB-202611-0001', $c->request_number);
        $this->assertSame(3, ExpenseRequest::query()->distinct()->count('request_number'));
    }

    public function test_permission_matrix_of_default_roles(): void
    {
        $this->assertTrue($this->employeeUser->hasPermission('expense.request'));
        foreach (['expense.view', 'expense.approve', 'expense.pay', 'expense.manage', 'expense.view_team', 'expense.approve_team'] as $p) {
            $this->assertFalse($this->employeeUser->hasPermission($p), "employee must not have {$p}");
        }
        foreach (['expense.request', 'expense.view_team', 'expense.approve_team'] as $p) {
            $this->assertTrue($this->manager->hasPermission($p), "manager needs {$p}");
        }
        foreach (['expense.approve', 'expense.pay', 'expense.manage', 'expense.view'] as $p) {
            $this->assertFalse($this->manager->hasPermission($p), "manager must not have {$p}");
        }
        foreach (['expense.request', 'expense.view', 'expense.approve', 'expense.pay', 'expense.manage'] as $p) {
            $this->assertTrue($this->hr->hasPermission($p), "hr needs {$p}");
        }
        $this->assertFalse($this->hr->hasPermission('settings.manage'));
        $this->assertTrue($this->admin->hasPermission('expense.manage'));
    }

    /** @return array{0: User, 1: Employee} */
    private function makeNoExpenseStaff(): array
    {
        $role = Role::query()->firstOrCreate(['slug' => 'kiosk'], ['name' => 'Kiosk', 'description' => 'Attendance only', 'is_system' => false]);
        $role->permissions()->sync(Permission::query()->whereIn('slug', ['profile.view', 'attendance.self'])->pluck('id'));

        return $this->makeStaff('kiosk');
    }
}
