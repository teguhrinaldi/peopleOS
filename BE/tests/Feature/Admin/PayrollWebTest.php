<?php

namespace Tests\Feature\Admin;

use App\Enums\PayrollStatus;
use App\Models\Activity;
use App\Models\PayrollPeriod;
use Illuminate\Support\Facades\Route;

class PayrollWebTest extends Batch2TestCase
{
    public function test_only_people_with_payroll_view_open_payroll(): void
    {
        $period = $this->finalizedPeriod();

        $this->signIn('employee');
        $this->get(route('admin.payroll'))->assertForbidden();

        [$manager] = $this->signIn('manager');
        $this->get(route('admin.payroll'))->assertForbidden();
        $this->get(route('admin.payroll.show', $period))->assertForbidden();
        $this->post(route('admin.payroll.store'), ['start_date' => '2026-08-01', 'end_date' => '2026-08-31'])->assertForbidden();
        $this->post(route('admin.payroll.approve', $period))->assertForbidden();
        $this->post(route('admin.payroll.finalize', $period))->assertForbidden();

        $this->actingAs($this->hr)->get(route('admin.payroll'))->assertOk()->assertSee('July 2026')->assertSee('IDR');
        $this->assertNotNull($manager);
    }

    public function test_sidebar_item_is_real_and_hidden_without_permission(): void
    {
        $this->actingAs($this->hr)->get(route('admin.dashboard'))->assertSee('Payroll')->assertSee('Salary Structures')->assertSee('Payslips');
        $this->signIn('manager');
        $this->get(route('admin.dashboard'))->assertDontSee('Salary Structures');
    }

    public function test_period_list_shows_totals_from_the_run_snapshot(): void
    {
        $period = $this->finalizedPeriod();
        $run = $period->run;

        $this->actingAs($this->hr)->get(route('admin.payroll', ['status' => 'FINALIZED']))
            ->assertOk()->assertSee(number_format((float) $run->total_net, 0, '.', ','))->assertSee('Finalized');
        $this->actingAs($this->hr)->get(route('admin.payroll', ['status' => 'DRAFT']))->assertOk()->assertSee('No periods match');
    }

    public function test_detail_page_shows_stepper_items_and_item_drawer(): void
    {
        $period = $this->finalizedPeriod();
        $item = $period->run->items()->first();

        $this->actingAs($this->hr)->get(route('admin.payroll.show', $period))
            ->assertOk()->assertSee('Workflow progress')->assertSee('Finalized and locked')->assertSee('Paula Payroll')
            ->assertDontSee('Generate payroll')->assertDontSee('Edit details')->assertDontSee('Submit for review');

        $this->actingAs($this->hr)->get(route('admin.payroll.items.show', [$period, $item]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertSee('Basic Salary')->assertSee('Transport Allowance')->assertSee('Cooperative Savings')->assertSee('Present days')->assertDontSee('<html', false);

        $this->actingAs($this->hr)->get(route('admin.payroll.items.show', [$period, $item]))->assertOk()->assertSee('Pay details');
    }

    public function test_items_search(): void
    {
        $this->paidPerson('Zed Zebra');
        $this->paidPerson('Amy Alpha');
        $period = $this->toReview($this->period());

        $this->actingAs($this->hr)->get(route('admin.payroll.show', ['period' => $period, 'q' => 'zebra']))->assertOk()->assertSee('Zed Zebra')->assertDontSee('Amy Alpha');
    }

    public function test_full_workflow_through_the_web(): void
    {
        $this->paidPerson();
        $this->actingAs($this->hr);

        $this->post(route('admin.payroll.store'), ['start_date' => '2026-07-01', 'end_date' => '2026-07-31'])->assertRedirect();
        $period = PayrollPeriod::query()->firstOrFail();
        $this->get(route('admin.payroll.show', $period))->assertSee('Generate payroll')->assertSee('Not generated yet');

        $this->post(route('admin.payroll.generate', $period))->assertRedirect()->assertSessionHas('success');
        $this->post(route('admin.payroll.submit', $period))->assertRedirect()->assertSessionHas('success');
        $this->assertSame(PayrollStatus::Review, $period->fresh()->status);

        // The person who submitted may not approve (company setting, default on): friendly message, no 500.
        $this->post(route('admin.payroll.approve', $period))->assertRedirect()->assertSessionHas('error');
        $this->assertStringContainsString('different person', session('error'));
        $this->assertSame(PayrollStatus::Review, $period->fresh()->status);

        $this->actingAs($this->admin);
        $this->post(route('admin.payroll.approve', $period))->assertRedirect()->assertSessionHas('success');
        $this->post(route('admin.payroll.finalize', $period))->assertRedirect()->assertSessionHas('success');
        $this->assertSame(PayrollStatus::Finalized, $period->fresh()->status);
        $this->post(route('admin.payroll.mark-paid', $period), ['payment_reference' => 'BATCH-77'])->assertRedirect()->assertSessionHas('success');
        $this->assertSame(PayrollStatus::Paid, $period->fresh()->status);
        $this->assertSame('BATCH-77', $period->fresh('run')->run->payment_reference);

        foreach (['payroll.generated', 'payroll.submitted', 'payroll.approved', 'payroll.finalized', 'payroll.paid', 'payroll_period.created'] as $action) {
            $this->assertTrue(Activity::query()->where('action', $action)->exists(), "missing audit row {$action}");
        }
        $this->get(route('admin.payroll.show', $period))->assertSee('Paid and locked')->assertSee('BATCH-77');
    }

    public function test_only_the_state_machine_and_permission_allow_buttons(): void
    {
        $this->paidPerson();
        $period = $this->toReview($this->period());

        // HR (manage + approve + finalize) in REVIEW: can return to draft / approve / cancel, not finalize.
        $html = $this->actingAs($this->hr)->get(route('admin.payroll.show', $period))->assertOk()->getContent();
        $this->assertStringContainsString('Return to draft', $html);
        $this->assertStringContainsString('Approve', $html);
        $this->assertStringNotContainsString('Finalize payroll', $html);
    }

    public function test_wrong_state_actions_are_friendly_never_500(): void
    {
        $this->paidPerson();
        $period = $this->period();
        $this->actingAs($this->admin);

        $this->post(route('admin.payroll.approve', $period))->assertRedirect()->assertSessionHas('error');
        $this->assertStringContainsString('now draft', session('error'));
        $this->post(route('admin.payroll.finalize', $period))->assertRedirect()->assertSessionHas('error');
        $this->post(route('admin.payroll.mark-paid', $period))->assertRedirect()->assertSessionHas('error');
        $this->post(route('admin.payroll.submit', $period))->assertRedirect()->assertSessionHas('error');
        $this->assertStringContainsString('Generate the payroll first', session('error'));

        $done = $this->toFinalized($this->period('2026-08-01', '2026-08-31'));
        $this->post(route('admin.payroll.generate', $done))->assertRedirect()->assertSessionHas('error');
        $this->post(route('admin.payroll.cancel', $done), ['reason' => 'Oops'])->assertRedirect()->assertSessionHas('error');
        $this->assertSame(PayrollStatus::Finalized, $done->fresh()->status);
    }

    public function test_negative_net_pay_lists_the_employees(): void
    {
        $poor = $this->person('Nelly Negative');
        $this->giveSalary($poor, '50000', extra: ['COOP' => '100000']);
        $this->present($poor, ['2026-07-01']);
        $period = $this->toReview($this->period());

        $this->actingAs($this->admin)->post(route('admin.payroll.approve', $period))->assertRedirect()->assertSessionHas('error');
        $this->assertStringContainsString('negative net pay', session('error'));
        $this->assertStringContainsString('Nelly Negative', implode(' ', session('error_items')));
        $this->assertSame(PayrollStatus::Review, $period->fresh()->status);

        $this->actingAs($this->hr)->get(route('admin.payroll.show', $period))->assertSee('negative net pay');
    }

    public function test_return_to_draft_and_cancel_need_a_reason(): void
    {
        $this->paidPerson();
        $period = $this->toReview($this->period());
        $this->actingAs($this->hr);

        $this->post(route('admin.payroll.return-to-draft', $period), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post(route('admin.payroll.return-to-draft', $period), ['reason' => 'Wrong overtime figures'])->assertRedirect()->assertSessionHas('success');
        $this->assertSame(PayrollStatus::Draft, $period->fresh()->status);
        $this->get(route('admin.payroll.show', $period))->assertSee('Wrong overtime figures');

        $this->post(route('admin.payroll.cancel', $period), [])->assertSessionHasErrors('reason');
        $this->post(route('admin.payroll.cancel', $period), ['reason' => 'Duplicate period'])->assertRedirect()->assertSessionHas('success');
        $this->get(route('admin.payroll.show', $period))->assertSee('This period was cancelled')->assertSee('Duplicate period')->assertDontSee('Generate payroll');
    }

    public function test_finalized_payroll_is_read_only_and_has_no_edit_or_delete_routes(): void
    {
        $period = $this->finalizedPeriod();
        $this->actingAs($this->hr);

        $this->put(route('admin.payroll.update', $period), ['name' => 'Hacked'])->assertRedirect()->assertSessionHas('error');
        $this->assertSame('July 2026', $period->fresh()->name);
        $this->delete('/admin/payroll/'.$period->id)->assertStatus(405);
        $this->delete('/admin/payroll/'.$period->id.'/items/'.$period->run->items()->value('id'))->assertStatus(405);
        $this->put('/admin/payroll/'.$period->id.'/items/'.$period->run->items()->value('id'))->assertStatus(405);

        $deletes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'admin/payroll') && in_array('DELETE', $r->methods(), true))->map->uri()->values()->all();
        $this->assertSame(['admin/payroll/{period}/adjustment-entries/{entry}'], $deletes, 'only DRAFT adjustment entries may be removed');
    }

    public function test_period_validation_overlap_and_length(): void
    {
        $this->actingAs($this->hr);
        $this->post(route('admin.payroll.store'), ['start_date' => '2026-07-01', 'end_date' => '2026-06-01'])->assertSessionHasErrors('end_date');
        $this->post(route('admin.payroll.store'), ['start_date' => '', 'end_date' => ''])->assertSessionHasErrors(['start_date', 'end_date']);
        $this->post(route('admin.payroll.store'), ['start_date' => '2026-07-01', 'end_date' => '2026-07-31'])->assertSessionHasNoErrors();
        $this->post(route('admin.payroll.store'), ['start_date' => '2026-07-15', 'end_date' => '2026-08-10'])->assertSessionHasErrors('start_date');
        $this->post(route('admin.payroll.store'), ['start_date' => '2026-09-01', 'end_date' => '2026-12-31'])->assertSessionHasErrors();
        $this->assertSame(1, PayrollPeriod::query()->count());
    }

    public function test_adjustment_flow(): void
    {
        $original = $this->finalizedPeriod();
        $employee = $original->run->items()->first();
        $outsider = $this->person('Outside Olivia');
        $this->actingAs($this->hr);

        $this->post(route('admin.payroll.adjustments.store', $original), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post(route('admin.payroll.adjustments.store', $original), ['reason' => 'Missed overtime'])->assertRedirect();
        $adjustment = PayrollPeriod::query()->where('type', 'ADJUSTMENT')->firstOrFail();
        $this->get(route('admin.payroll.show', $adjustment))->assertOk()->assertSee('Adjustment period')->assertSee('Add entry')->assertSee('Missed overtime');

        $this->post(route('admin.payroll.entries.store', $adjustment), ['employee_id' => $outsider->id, 'kind' => 'EARNING', 'label' => 'Bonus', 'amount' => '1000', 'reason' => 'Test'])
            ->assertSessionHasErrors('employee_id');
        $this->post(route('admin.payroll.entries.store', $adjustment), ['employee_id' => $employee->employee_id, 'kind' => 'EARNING', 'label' => 'Missed overtime', 'amount' => '-5', 'reason' => 'Test'])
            ->assertSessionHasErrors('amount');
        $this->post(route('admin.payroll.entries.store', $adjustment), ['employee_id' => $employee->employee_id, 'kind' => 'EARNING', 'label' => 'Missed overtime', 'amount' => '250000.50', 'reason' => 'Supervisor approved'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $entry = $adjustment->adjustments()->firstOrFail();
        $this->assertSame('250000.50', (string) $entry->amount);
        $this->get(route('admin.payroll.show', $adjustment))->assertSee('Missed overtime')->assertSee('250,001');

        $this->delete(route('admin.payroll.entries.destroy', [$adjustment, $entry]))->assertRedirect()->assertSessionHas('success');
        $this->assertSame(0, $adjustment->adjustments()->count());

        // Not available on a non-finalized period.
        $draft = $this->period('2026-09-01', '2026-09-30');
        $this->post(route('admin.payroll.adjustments.store', $draft), ['reason' => 'x y z'])->assertRedirect()->assertSessionHas('error');
        $this->get(route('admin.payroll.show', $original))->assertSee('Create adjustment period');
    }

    public function test_payroll_pages_escape_user_content(): void
    {
        $xss = '<script>alert(1)</script>';
        $this->paidPerson($xss);
        $period = $this->toFinalized($this->period());
        \DB::table('payroll_periods')->where('id', $period->id)->update(['name' => $xss, 'notes' => $xss]);
        \DB::table('payroll_runs')->where('payroll_period_id', $period->id)->update(['payment_reference' => $xss]);
        $item = $period->run->items()->first();

        foreach ([route('admin.payroll'), route('admin.payroll.show', $period), route('admin.payroll.items.show', [$period, $item]), route('admin.payslips'), route('admin.reports.show', 'payroll.detail')] as $url) {
            $html = $this->actingAs($this->hr)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString($xss, $html, $url);
        }
    }

    public function test_money_is_formatted_from_strings_with_run_decimals(): void
    {
        $this->paidPerson('Dec Imal', '1234567.89');
        $period = $this->toReview($this->period());
        $this->assertSame(0, $period->run->decimals);

        $html = $this->actingAs($this->hr)->get(route('admin.payroll.show', $period))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/1,[23]\d\d,\d{3}/', $html);
        $this->assertStringNotContainsString('E+', $html);
    }
}
