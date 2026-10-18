<?php

namespace Tests\Feature\Admin;

use App\Enums\LeaveStatus;
use App\Models\Activity;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\Leave\LeaveBalanceService;

class EmployeeLeaveTabTest extends AdminTestCase
{
    private function filePayload(LeaveType $type, array $overrides = []): array
    {
        return array_merge(['leave_type_id' => $type->id, 'start_date' => '2026-10-12', 'end_date' => '2026-10-14', 'reason' => 'Filed by HR'], $overrides);
    }

    public function test_the_tab_shows_balances_per_type_and_the_years_requests(): void
    {
        $this->signIn('hr');
        $annual = $this->leaveType();
        $this->leaveType('SICK', 'Sick Leave', ['annual_allowance' => null]);
        $employee = $this->person('Report Rita');
        $this->leaveFor($employee, $annual, '2026-10-05', '2026-10-06');

        $response = $this->get("/admin/employees/{$employee->id}?tab=leave")->assertOk();
        $summary = $response->viewData('leave');

        $this->assertSame(2026, $summary['year']);
        $this->assertCount(2, $summary['balances']);
        $this->assertSame('8.0', $summary['balances'][0]['balance']->available(), '10 allowance minus 2 reserved');
        $this->assertTrue($summary['balances'][1]['balance']->isUnlimited());
        $response->assertSee('of 10 days available')->assertSee('Unlimited')->assertSee('Annual Leave');
        $this->assertCount(1, $summary['requests']);
        $this->get("/admin/employees/{$employee->id}?tab=leave&year=2025")->assertOk()->assertSee('No leave in 2025');
    }

    public function test_managers_see_the_tab_for_reports_only_and_cannot_manage(): void
    {
        [, $me] = $this->signIn('manager');
        $this->leaveType();
        $report = $this->person('Report Rita', [], ['manager_id' => $me->id]);
        $stranger = $this->person('Stranger Stan');

        $this->get("/admin/employees/{$report->id}?tab=leave")->assertOk()->assertSee('Annual Leave')
            ->assertDontSee('File leave for')->assertDontSee('adjust-balance');
        $this->get("/admin/employees/{$stranger->id}?tab=leave")->assertForbidden();

        $balance = app(LeaveBalanceService::class)->balanceFor($report, LeaveType::query()->firstOrFail(), 2026);
        $this->post("/admin/employees/{$report->id}/leave", $this->filePayload(LeaveType::query()->firstOrFail()))->assertForbidden();
        $this->post("/admin/employees/{$report->id}/leave-balances/{$balance->id}/adjust", ['delta' => 2, 'reason' => 'x'])->assertForbidden();
    }

    public function test_hr_files_leave_on_behalf_and_it_enters_the_review_queue(): void
    {
        [$hr] = $this->signIn('hr');
        $type = $this->leaveType();
        $employee = $this->person('No App Nia');

        $this->post("/admin/employees/{$employee->id}/leave", $this->filePayload($type))
            ->assertRedirect("/admin/employees/{$employee->id}?tab=leave")->assertSessionHas('success');

        $request = LeaveRequest::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame(LeaveStatus::Pending, $request->status);
        $this->assertSame($hr->id, $request->requested_by);
        $this->assertSame('3.0', (string) $request->days);
        $this->assertTrue(Activity::query()->where('action', 'leave.submitted')->where('actor_id', $hr->id)->exists());
        $this->assertSame([$request->id], $this->get('/admin/leave-requests')->viewData('requests')->getCollection()->pluck('id')->all());
        $this->get("/admin/leave-requests/{$request->id}?panel=1")->assertSee('Filed by '.$hr->name);
    }

    public function test_filing_on_behalf_applies_the_same_rules_as_the_app(): void
    {
        $this->signIn('hr');
        $type = $this->leaveType('ANNUAL', 'Annual Leave', ['annual_allowance' => '2.0', 'min_notice_days' => 3, 'requires_reason' => true]);
        $employee = $this->person('Rule Rae');
        $url = "/admin/employees/{$employee->id}/leave";

        $this->post($url, $this->filePayload($type, ['start_date' => '2026-10-12', 'end_date' => '2026-10-14']))
            ->assertSessionHasErrors(['start_date' => 'Not enough balance: 2.0 day(s) available, 3.0 requested.']);
        $this->post($url, $this->filePayload($type, ['start_date' => '2026-10-01', 'end_date' => '2026-10-01']))->assertSessionHasErrors('start_date'); // notice
        $this->post($url, $this->filePayload($type, ['start_date' => '2026-10-12', 'end_date' => '2026-10-12', 'reason' => '']))->assertSessionHasErrors('reason');
        $this->post($url, $this->filePayload($type, ['start_date' => '2026-10-10', 'end_date' => '2026-10-11']))->assertSessionHasErrors('start_date'); // weekend only
        $this->post($url, $this->filePayload($type, ['start_date' => '2026-10-14', 'end_date' => '2026-10-12']))->assertSessionHasErrors('end_date');
        $this->post($url, $this->filePayload($type, ['half_day' => 'XX']))->assertSessionHasErrors('half_day');
        $this->post($url, $this->filePayload($type, ['leave_type_id' => 99999]))->assertSessionHasErrors('leave_type_id');

        $this->assertSame(0, LeaveRequest::query()->count(), 'a failed filing leaves no draft behind');

        $this->post($url, $this->filePayload($type, ['start_date' => '2026-10-12', 'end_date' => '2026-10-12']))->assertSessionHasNoErrors();
        $this->post($url, $this->filePayload($type, ['start_date' => '2026-10-12', 'end_date' => '2026-10-12']))->assertSessionHasErrors('start_date'); // overlap
        $this->assertSame(1, LeaveRequest::query()->count());
    }

    public function test_a_half_day_can_be_filed_for_a_single_date(): void
    {
        $this->signIn('hr');
        $type = $this->leaveType();
        $employee = $this->person('Half Hana');

        $this->post("/admin/employees/{$employee->id}/leave", ['leave_type_id' => $type->id, 'start_date' => '2026-10-13', 'half_day' => 'AM'])->assertSessionHasNoErrors();

        $this->assertSame('0.5', (string) LeaveRequest::query()->firstOrFail()->days);
    }

    public function test_terminated_and_inactive_employees_get_no_filing_button_and_the_server_refuses(): void
    {
        $this->signIn('hr');
        $type = $this->leaveType();
        $gone = $this->person('Gone Gus', ['status' => 'TERMINATED', 'terminated_at' => '2026-06-30']);

        $this->get("/admin/employees/{$gone->id}?tab=leave")->assertOk()->assertDontSee('File leave for');
        $this->post("/admin/employees/{$gone->id}/leave", $this->filePayload($type))->assertSessionHas('error');
        $this->assertSame(0, LeaveRequest::query()->count());
    }

    public function test_hr_adjusts_a_balance_with_a_reason_and_it_is_audited(): void
    {
        [$hr] = $this->signIn('hr');
        $type = $this->leaveType();
        $employee = $this->person('Adjust Abe');
        $balance = app(LeaveBalanceService::class)->balanceFor($employee, $type, 2026);
        $url = "/admin/employees/{$employee->id}/leave-balances/{$balance->id}/adjust";

        $this->post($url, ['delta' => '2', 'reason' => ''])->assertSessionHasErrors('reason');
        $this->post($url, ['delta' => '0', 'reason' => 'x'])->assertSessionHasErrors('delta');
        $this->post($url, ['delta' => '1.3', 'reason' => 'x'])->assertSessionHasErrors('delta');
        $this->post($url, ['delta' => 'abc', 'reason' => 'x'])->assertSessionHasErrors('delta');
        $this->assertSame('0.0', $balance->fresh()->adjustment);

        $this->post($url, ['delta' => '+2.5', 'reason' => 'Overtime comp day'])->assertRedirect("/admin/employees/{$employee->id}?tab=leave&year=2026")->assertSessionHas('success');
        $this->assertSame('2.5', $balance->fresh()->adjustment);
        $this->assertSame('12.5', $balance->fresh()->available());

        $log = Activity::query()->where('action', 'leave_balance.adjusted')->firstOrFail();
        $this->assertSame($hr->id, $log->actor_id);
        $this->assertSame('Overtime comp day', $log->new_values['reason']);
        $this->assertSame('2.5', $log->new_values['delta']);

        $this->post($url, ['delta' => '-20', 'reason' => 'Too much'])->assertSessionHasErrors(['delta' => 'The adjustment would make the balance negative.']);
        $this->assertSame('2.5', $balance->fresh()->adjustment);
    }

    public function test_a_balance_of_another_employee_cannot_be_adjusted_through_a_mismatched_url(): void
    {
        $this->signIn('hr');
        $type = $this->leaveType();
        $a = $this->person('Person A');
        $b = $this->person('Person B');
        $balanceOfB = app(LeaveBalanceService::class)->balanceFor($b, $type, 2026);

        $this->post("/admin/employees/{$a->id}/leave-balances/{$balanceOfB->id}/adjust", ['delta' => '5', 'reason' => 'sneaky'])->assertNotFound();
        $this->assertSame('0.0', LeaveBalance::query()->find($balanceOfB->id)->adjustment);
    }
}
