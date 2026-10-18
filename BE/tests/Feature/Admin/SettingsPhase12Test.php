<?php

namespace Tests\Feature\Admin;

use App\Models\Activity;
use App\Models\Employee;
use App\Services\Admin\EmployeeFormOptions;
use App\Support\DateDisplay;
use App\Support\Settings;

class SettingsPhase12Test extends AdminTestCase
{
    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test Co', 'timezone' => 'Asia/Jakarta', 'address' => 'Jl. Test 1',
            'currency' => 'idr', 'date_format' => 'd M Y', 'employee_number_prefix' => 'emp-', 'employee_number_digits' => 4,
            'leave_year_start_month' => 1, 'payroll_require_separate_approver' => 1, 'payslip_prefix' => 'ps',
            'payroll_rounding_decimals' => 'auto', 'documents_expiring_days' => 30,
        ], $overrides);
    }

    public function test_the_form_shows_every_configurable_setting_with_its_current_value(): void
    {
        $this->signIn('admin');

        $page = $this->get('/admin/settings')->assertOk();
        foreach (['Currency', 'Date format', 'Employee numbers', 'Leave year starts in', 'Separate approver', 'Payslip number prefix', 'Rounding', 'Warn this many days before expiry', 'Attendance defaults'] as $label) {
            $page->assertSee($label);
        }
        $this->assertSame('IDR', $page->viewData('values')['general.currency']);
        $this->assertSame('EMP-0042', $page->viewData('exampleNumber'));
    }

    public function test_changed_settings_are_saved_through_settings_set_and_audited_with_old_and_new(): void
    {
        [$admin] = $this->signIn('admin');

        $this->put('/admin/settings', $this->payload(['currency' => 'usd', 'employee_number_prefix' => 'st-', 'employee_number_digits' => 5, 'leave_year_start_month' => 4,
            'payroll_require_separate_approver' => 0, 'payslip_prefix' => 'slip', 'payroll_rounding_decimals' => '2', 'documents_expiring_days' => 60, 'date_format' => 'd/m/Y']))
            ->assertRedirect('/admin/settings')->assertSessionHas('success');

        $settings = app(Settings::class);
        $this->assertSame('USD', $settings->string('general.currency'));
        $this->assertSame('ST-', $settings->string('employee.number_prefix'));
        $this->assertSame(5, $settings->int('employee.number_digits'));
        $this->assertSame(4, $settings->int('leave.year_start_month'));
        $this->assertFalse($settings->bool('payroll.require_separate_approver'));
        $this->assertSame('SLIP', $settings->string('payroll.payslip_prefix'));
        $this->assertSame('2', $settings->string('payroll.rounding_decimals'));
        $this->assertSame(60, $settings->int('documents.expiring_days'));

        $log = Activity::query()->where('action', 'settings.updated')->firstOrFail();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame('IDR', $log->old_values['general.currency']);
        $this->assertSame('USD', $log->new_values['general.currency']);
        $this->assertTrue($log->old_values['payroll.require_separate_approver']);
        $this->assertFalse($log->new_values['payroll.require_separate_approver']);
    }

    public function test_saving_unchanged_values_writes_no_audit_row(): void
    {
        $this->signIn('admin');

        $this->put('/admin/settings', $this->payload())->assertSessionHasNoErrors();
        $this->assertSame(0, Activity::query()->where('action', 'settings.updated')->count());

        $this->put('/admin/settings', $this->payload(['documents_expiring_days' => 45]));
        $this->put('/admin/settings', $this->payload(['documents_expiring_days' => 45]));
        $rows = Activity::query()->where('action', 'settings.updated')->get();
        $this->assertCount(1, $rows);
        $this->assertSame(['documents.expiring_days'], array_keys($rows->first()->new_values), 'only the changed key is recorded');
    }

    public function test_settings_validation(): void
    {
        $this->signIn('admin');

        $this->put('/admin/settings', $this->payload(['currency' => 'ZZZ']))->assertSessionHasErrors(['currency' => 'Choose a valid ISO 4217 currency code, e.g. IDR or USD.']);
        $this->put('/admin/settings', $this->payload(['currency' => 'RUPIAH']))->assertSessionHasErrors('currency');
        $this->put('/admin/settings', $this->payload(['date_format' => 'Y/d/m']))->assertSessionHasErrors('date_format');
        $this->put('/admin/settings', $this->payload(['employee_number_prefix' => 'bad prefix!']))->assertSessionHasErrors('employee_number_prefix');
        $this->put('/admin/settings', $this->payload(['employee_number_prefix' => 'WAYTOOLONGPREFIX']))->assertSessionHasErrors('employee_number_prefix');
        $this->put('/admin/settings', $this->payload(['employee_number_digits' => 0]))->assertSessionHasErrors('employee_number_digits');
        $this->put('/admin/settings', $this->payload(['employee_number_digits' => 11]))->assertSessionHasErrors('employee_number_digits');
        $this->put('/admin/settings', $this->payload(['leave_year_start_month' => 13]))->assertSessionHasErrors('leave_year_start_month');
        $this->put('/admin/settings', $this->payload(['leave_year_start_month' => 0]))->assertSessionHasErrors('leave_year_start_month');
        $this->put('/admin/settings', $this->payload(['payslip_prefix' => '']))->assertSessionHasErrors('payslip_prefix');
        $this->put('/admin/settings', $this->payload(['payroll_rounding_decimals' => '5']))->assertSessionHasErrors('payroll_rounding_decimals');
        $this->put('/admin/settings', $this->payload(['documents_expiring_days' => 0]))->assertSessionHasErrors('documents_expiring_days');
        $this->put('/admin/settings', $this->payload(['documents_expiring_days' => 400]))->assertSessionHasErrors('documents_expiring_days');

        $this->assertSame(0, Activity::query()->where('action', 'settings.updated')->count());
        $this->assertSame('IDR', app(Settings::class)->string('general.currency'));
    }

    public function test_an_empty_employee_number_prefix_is_allowed(): void
    {
        $this->signIn('admin');

        $this->put('/admin/settings', $this->payload(['employee_number_prefix' => '']))->assertSessionHasNoErrors();

        $this->assertSame('', app(Settings::class)->string('employee.number_prefix'));
        $this->assertSame('0042', app(Settings::class)->nextEmployeeNumber(42));
    }

    public function test_hr_cannot_change_these_settings(): void
    {
        $this->signIn('hr');

        $this->put('/admin/settings', $this->payload(['currency' => 'usd']))->assertForbidden();
        $this->assertSame('IDR', app(Settings::class)->string('general.currency'));
    }

    public function test_the_date_format_setting_changes_how_dates_are_shown(): void
    {
        $this->signIn('admin');
        $employee = $this->person('Report Rita', ['join_date' => '2024-03-05']);
        $this->leaveFor($employee, $this->leaveType(), '2026-10-05');

        $this->get('/admin/leave-requests')->assertSee('05 Oct 2026');
        $this->put('/admin/settings', $this->payload(['date_format' => 'Y-m-d']))->assertSessionHasNoErrors();
        DateDisplay::forget();
        $this->get('/admin/leave-requests')->assertSee('2026-10-05')->assertDontSee('05 Oct 2026');
        $this->get('/admin/employees')->assertSee('2024-03-05');
    }

    public function test_the_attendance_defaults_card_toggles_selfie_on_the_default_policy_and_audits(): void
    {
        [$admin] = $this->signIn('admin');
        $this->assertFalse($this->policy->require_selfie);

        $this->get('/admin/settings')->assertOk()->assertSee('Selfie required at check-in')->assertSee('Standard');
        $this->put('/admin/settings/attendance', ['require_selfie' => 1])->assertRedirect()->assertSessionHas('success');

        $this->assertTrue($this->policy->fresh()->require_selfie);
        $log = Activity::query()->where('action', 'attendance_policy.updated')->firstOrFail();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertFalse((bool) $log->old_values['require_selfie']);
        $this->assertTrue((bool) $log->new_values['require_selfie']);

        $this->put('/admin/settings/attendance', ['require_selfie' => 0])->assertSessionHas('success');
        $this->assertFalse($this->policy->fresh()->require_selfie);
        $this->put('/admin/settings/attendance', [])->assertSessionHasErrors('require_selfie');
    }

    public function test_the_selfie_toggle_without_a_default_policy_explains_what_to_do(): void
    {
        $this->signIn('admin');
        $this->policy->forceFill(['is_default' => false])->save();

        $this->get('/admin/settings')->assertOk()->assertSee('There is no default attendance policy yet');
        $this->from('/admin/settings')->put('/admin/settings/attendance', ['require_selfie' => 1])->assertSessionHas('error');
    }

    public function test_the_next_employee_number_follows_the_prefix_and_digits_and_counts_deleted_ones(): void
    {
        $this->signIn('admin');
        $options = app(EmployeeFormOptions::class);
        $this->assertSame('EMP-0002', $options->suggestedNumber(), 'the signed-in admin already holds EMP-0001');

        $this->person('Seven', ['employee_number' => 'EMP-0007']);
        $this->person('Twelve', ['employee_number' => 'EMP-0012']);
        $this->person('Other style', ['employee_number' => 'X-100']);
        $this->assertSame('EMP-0013', $options->suggestedNumber());

        Employee::query()->where('employee_number', 'EMP-0012')->firstOrFail()->delete();
        $this->assertSame('EMP-0013', $options->suggestedNumber(), 'a soft-deleted number still blocks reuse');

        app(Settings::class)->set(['employee.number_prefix' => 'ST/', 'employee.number_digits' => 6]);
        $this->assertSame('ST/000001', $options->suggestedNumber());
        $this->person('Staff', ['employee_number' => 'ST/000009']);
        $this->assertSame('ST/000010', $options->suggestedNumber());
    }

    public function test_the_create_form_prefills_the_suggestion_but_it_stays_editable(): void
    {
        $this->signIn('hr');
        $this->person('Seven', ['employee_number' => 'EMP-0007']);

        $this->get('/admin/employees/create')->assertOk()->assertSee('value="EMP-0008"', false)->assertSee('Suggested from Settings');

        $this->post('/admin/employees', [
            'employee_number' => 'EMP-0100', 'full_name' => 'Manual Mia', 'join_date' => '2026-09-01', 'employment_type' => 'permanent', 'status' => 'ACTIVE',
            'department_id' => $this->department->id, 'position_id' => $this->position->id,
        ])->assertSessionHasNoErrors();
        $this->assertTrue(Employee::query()->where('employee_number', 'EMP-0100')->exists());
        $this->get('/admin/employees/create')->assertSee('value="EMP-0101"', false);
    }

    public function test_the_edit_form_does_not_suggest_a_number(): void
    {
        $this->signIn('hr');
        $employee = $this->person('Keep Kai', ['employee_number' => 'EMP-0042']);

        $this->get("/admin/employees/{$employee->id}/edit")->assertOk()->assertSee('value="EMP-0042"', false)->assertDontSee('Suggested from Settings');
    }
}
