<?php

namespace Tests\Feature\Admin;

use App\Models\Activity;
use App\Models\LeavePolicy;
use App\Models\LeaveType;

class LeaveTypesTest extends AdminTestCase
{
    /** @return array<string, mixed> */
    private function payload(array $type = [], array $policy = []): array
    {
        return $type + [
            'code' => 'study', 'name' => 'Study Leave', 'description' => 'Exams', 'color' => 'teal', 'is_paid' => 1, 'is_active' => 1, 'sort_order' => 4,
            'policy' => $policy + [
                'annual_allowance' => '5', 'prorate_first_year' => 1, 'carry_over_max' => '2', 'allow_negative' => 0, 'max_negative_days' => '0',
                'allow_half_day' => 1, 'allow_overlap' => 0, 'min_notice_days' => '7', 'max_past_days' => '0', 'max_consecutive_days' => '3', 'requires_reason' => 1,
            ],
        ];
    }

    public function test_hr_creates_a_type_with_its_default_policy_and_it_is_audited(): void
    {
        [$hr] = $this->signIn('hr');

        $this->post('/admin/leave-types', $this->payload())->assertSessionHasNoErrors()->assertSessionHas('success');

        $type = LeaveType::query()->where('code', 'STUDY')->firstOrFail();
        $this->assertSame('teal', $type->color);
        $policy = $type->policies()->whereNull('employment_type')->firstOrFail();
        $this->assertSame('5.0', $policy->annual_allowance);
        $this->assertSame(7, $policy->min_notice_days);
        $this->assertSame(3, $policy->max_consecutive_days);
        $this->assertTrue($policy->requires_reason);
        $this->assertTrue(Activity::query()->where('action', 'leave_type.created')->where('actor_id', $hr->id)->exists());
    }

    public function test_type_validation_covers_code_colour_and_policy_fields(): void
    {
        $this->signIn('hr');
        $this->leaveType();

        $this->post('/admin/leave-types', $this->payload(['code' => 'annual']))->assertSessionHasErrors(['code' => 'This code is already used by another leave type.']);
        $this->post('/admin/leave-types', $this->payload(['code' => 'bad code']))->assertSessionHasErrors('code');
        $this->post('/admin/leave-types', $this->payload(['color' => 'magenta']))->assertSessionHasErrors('color');
        $this->post('/admin/leave-types', $this->payload(['name' => '']))->assertSessionHasErrors('name');
        $this->post('/admin/leave-types', $this->payload([], ['annual_allowance' => '12.3']))->assertSessionHasErrors(['policy.annual_allowance' => 'Use whole or half days (e.g. 12 or 12.5).']);
        $this->post('/admin/leave-types', $this->payload([], ['annual_allowance' => '-1']))->assertSessionHasErrors('policy.annual_allowance');
        $this->post('/admin/leave-types', $this->payload([], ['min_notice_days' => '9999']))->assertSessionHasErrors('policy.min_notice_days');
        $this->post('/admin/leave-types', $this->payload([], ['max_consecutive_days' => '0']))->assertSessionHasErrors('policy.max_consecutive_days');
        $this->assertSame(1, LeaveType::query()->count());
    }

    public function test_an_empty_allowance_means_unlimited_and_borrowing_off_zeroes_the_limit(): void
    {
        $this->signIn('hr');

        $this->post('/admin/leave-types', $this->payload(['code' => 'free'], ['annual_allowance' => '', 'allow_negative' => 0, 'max_negative_days' => '5']))->assertSessionHasNoErrors();

        $policy = LeaveType::query()->where('code', 'FREE')->firstOrFail()->policies()->firstOrFail();
        $this->assertNull($policy->annual_allowance);
        $this->assertTrue($policy->isUnlimited());
        $this->assertSame('0.0', $policy->max_negative_days);
    }

    public function test_updating_saves_type_and_default_policy_together_with_old_and_new_audit_values(): void
    {
        $this->signIn('hr');
        $type = $this->leaveType();

        $this->put("/admin/leave-types/{$type->id}", $this->payload(['code' => 'ANNUAL', 'name' => 'Annual Leave (renamed)', 'color' => 'purple', 'is_active' => 0], ['annual_allowance' => '14']))
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $type->refresh();
        $this->assertSame('Annual Leave (renamed)', $type->name);
        $this->assertFalse($type->is_active);
        $this->assertSame('14.0', $type->policies()->whereNull('employment_type')->firstOrFail()->annual_allowance);
        $log = Activity::query()->where('action', 'leave_type.updated')->firstOrFail();
        $this->assertSame('Annual Leave', $log->old_values['name']);
        $this->assertSame('Annual Leave (renamed)', $log->new_values['name']);
    }

    public function test_employment_type_policies_can_be_added_changed_and_removed(): void
    {
        $this->signIn('hr');
        $type = $this->leaveType();
        $url = "/admin/leave-types/{$type->id}/policies";
        $ep = ['annual_allowance' => '6', 'carry_over_max' => '0', 'max_negative_days' => '0', 'min_notice_days' => '0', 'max_past_days' => '0', 'allow_half_day' => 1];

        $this->post($url, ['employment_type' => 'contract', 'ep' => $ep])->assertSessionHasNoErrors()->assertSessionHas('success');
        $contract = $type->policies()->where('employment_type', 'contract')->firstOrFail();
        $this->assertSame('6.0', $contract->annual_allowance);

        $this->post($url, ['employment_type' => 'contract', 'ep' => ['annual_allowance' => '8'] + $ep])->assertSessionHasNoErrors();
        $this->assertSame('8.0', $contract->fresh()->annual_allowance);
        $this->assertSame(1, $type->policies()->where('employment_type', 'contract')->count(), 'upsert, not duplicate');

        $this->post($url, ['employment_type' => 'astronaut', 'ep' => $ep])->assertSessionHasErrors('employment_type');
        $this->post($url, ['employment_type' => 'intern', 'ep' => ['annual_allowance' => '1.7'] + $ep])->assertSessionHasErrors('ep.annual_allowance');

        $this->delete("/admin/leave-types/{$type->id}/policies/{$contract->id}")->assertSessionHas('success');
        $this->assertNull(LeavePolicy::query()->find($contract->id));
        $default = $type->policies()->whereNull('employment_type')->firstOrFail();
        $this->delete("/admin/leave-types/{$type->id}/policies/{$default->id}")->assertSessionHas('error');
        $this->assertNotNull($default->fresh());
    }

    public function test_a_policy_url_cannot_touch_another_types_policy(): void
    {
        $this->signIn('hr');
        $a = $this->leaveType();
        $b = $this->leaveType('SICK', 'Sick Leave');
        $policyOfB = $b->policies()->firstOrFail();

        $this->delete("/admin/leave-types/{$a->id}/policies/{$policyOfB->id}")->assertNotFound();
        $this->assertNotNull($policyOfB->fresh());
    }

    public function test_a_type_with_requests_can_only_be_deactivated_and_an_unused_one_deleted(): void
    {
        $this->signIn('hr');
        $used = $this->leaveType();
        $unused = $this->leaveType('SPARE', 'Spare Leave');
        $this->leaveFor($this->person('Req Rae'), $used);

        $this->get('/admin/leave-types')->assertOk()->assertSee('1 pending');
        $this->from('/admin/leave-types')->delete("/admin/leave-types/{$used->id}")->assertSessionHas('error', 'This leave type has requests. Deactivate it instead.');
        $this->assertNotSoftDeleted('leave_types', ['id' => $used->id]);

        $this->delete("/admin/leave-types/{$unused->id}")->assertSessionHas('success');
        $this->assertSoftDeleted('leave_types', ['id' => $unused->id]);
        $this->assertTrue(Activity::query()->where('action', 'leave_type.deleted')->exists());
    }

    public function test_the_form_pages_render_with_the_colour_picker_and_helper_text(): void
    {
        $this->signIn('hr');
        $type = $this->leaveType();

        $page = $this->get("/admin/leave-types/{$type->id}/edit")->assertOk();
        foreach (['Blue', 'Green', 'Orange', 'Red', 'Purple', 'Teal', 'Gray'] as $colour) {
            $page->assertSee($colour);
        }
        $page->assertSee('Leave empty for unlimited')->assertSee('Carry-over cap')->assertSee('Employment-type policies');
        $this->get('/admin/leave-types/create')->assertOk()->assertSee('Default policy');
    }

    public function test_rejected_submissions_keep_input_and_show_errors_on_the_right_form_only(): void
    {
        $this->signIn('hr');
        $type = $this->leaveType();

        $this->followingRedirects()->from("/admin/leave-types/{$type->id}/edit")
            ->put("/admin/leave-types/{$type->id}", $this->payload(['code' => 'ANNUAL', 'name' => 'Kept Name'], ['annual_allowance' => '12.3']))
            ->assertSee('Kept Name')->assertSee('Use whole or half days');
    }
}
