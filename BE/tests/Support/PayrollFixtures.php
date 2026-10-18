<?php

namespace Tests\Support;

use App\Enums\SalaryCalculation;
use App\Enums\SalaryComponentKind;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeSalaryComponent;
use App\Models\OvertimeRequest;
use App\Models\PayrollPeriod;
use App\Models\SalaryComponent;
use App\Models\User;
use App\Services\Attendance\ScheduleService;
use App\Services\Payroll\PayrollGenerator;
use App\Services\Payroll\PayrollPeriodService;
use App\Services\Payroll\PayrollWorkflow;
use Illuminate\Support\Facades\DB;

/**
 * Payroll test data on top of WorkplaceFixtures: a small component catalog (demo values, not statutory),
 * an HR preparer and an Admin approver.
 */
trait PayrollFixtures
{
    /** @var array<string, SalaryComponent> */
    protected array $components = [];

    protected User $hr;

    protected User $admin;

    protected function setUpPayroll(): void
    {
        $defs = [
            'BASIC' => ['Basic Salary', 'EARNING', 'FIXED', true, null, 0],
            'TRANSPORT' => ['Transport Allowance', 'EARNING', 'PER_PRESENT_DAY', false, '50000', 10],
            'OVERTIME' => ['Overtime', 'EARNING', 'PER_OVERTIME_HOUR', false, '50000', 20],
            'POSITION' => ['Position Allowance', 'EARNING', 'FIXED', false, '0', 30],
            'UNPAID' => ['Unpaid Leave', 'DEDUCTION', 'UNPAID_LEAVE_DEDUCTION', false, null, 40],
            'COOP' => ['Cooperative Savings', 'DEDUCTION', 'FIXED', false, '100000', 50],
        ];
        foreach ($defs as $code => [$name, $kind, $calc, $basic, $amount, $sort]) {
            $this->components[$code] = SalaryComponent::query()->create([
                'company_id' => $this->company->id, 'code' => $code, 'name' => $name,
                'kind' => SalaryComponentKind::from($kind), 'calculation' => SalaryCalculation::from($calc),
                'is_basic' => $basic, 'default_amount' => $amount, 'sort_order' => $sort,
            ]);
        }

        [$this->hr] = $this->makeStaff('hr');
        [$this->admin] = $this->makeStaff('admin');
    }

    /** @param array<string, string> $extra component code => amount */
    protected function giveSalary(Employee $employee, string $basic, string $from = '2026-01-01', array $extra = ['TRANSPORT' => '50000', 'COOP' => '100000'], ?string $to = null): void
    {
        foreach (['BASIC' => $basic] + $extra as $code => $amount) {
            EmployeeSalaryComponent::query()->create([
                'employee_id' => $employee->id, 'salary_component_id' => $this->components[$code]->id,
                'amount' => $this->components[$code]->calculation->usesAmount() ? $amount : null,
                'effective_from' => $from, 'effective_to' => $to,
            ]);
        }
    }

    protected function period(string $start = '2026-07-01', string $end = '2026-07-31'): PayrollPeriod
    {
        return app(PayrollPeriodService::class)->createRegular($this->hr, $start, $end, null, $end);
    }

    protected function generate(PayrollPeriod $period, ?User $by = null): PayrollPeriod
    {
        app(PayrollGenerator::class)->generate($by ?? $this->hr, $period);

        return $period->fresh('run');
    }

    protected function toReview(PayrollPeriod $period): PayrollPeriod
    {
        $this->generate($period);
        app(PayrollWorkflow::class)->submit($this->hr, $period);

        return $period->fresh('run');
    }

    protected function toFinalized(PayrollPeriod $period): PayrollPeriod
    {
        $this->toReview($period);
        app(PayrollWorkflow::class)->approve($this->admin, $period);
        app(PayrollWorkflow::class)->finalize($this->admin, $period);

        return $period->fresh('run');
    }

    /** Attendance rows with a check-in (status CHECKED_OUT unless given). */
    protected function present(Employee $employee, array $dates, string $status = 'CHECKED_OUT'): void
    {
        foreach ($dates as $date) {
            $schedule = app(ScheduleService::class)->forDate($employee, $date);
            Attendance::query()->create([
                'employee_id' => $employee->id, 'attendance_schedule_id' => $schedule->id, 'work_date' => $date,
                'status' => $status, 'check_in_at' => $schedule->expected_start_at ?? $date.' 01:00:00',
                'check_out_at' => $schedule->expected_end_at,
            ]);
        }
    }

    protected function approvedOvertime(Employee $employee, string $date, int $minutes): void
    {
        OvertimeRequest::query()->create([
            'employee_id' => $employee->id, 'work_date' => $date, 'start_at' => $date.' 10:00:00', 'end_at' => $date.' 12:00:00',
            'requested_minutes' => $minutes, 'approved_minutes' => $minutes, 'reason' => 'Test overtime', 'status' => 'APPROVED',
        ]);
    }

    /** Approved unpaid leave days for an employee (direct rows; the leave module owns the real flow). */
    protected function unpaidLeave(Employee $employee, array $dates): void
    {
        $typeId = DB::table('leave_types')->where('code', 'UNPAID')->value('id') ?? DB::table('leave_types')->insertGetId([
            'company_id' => $this->company->id, 'code' => 'UNPAID', 'name' => 'Unpaid Leave', 'is_paid' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $requestId = DB::table('leave_requests')->insertGetId([
            'employee_id' => $employee->id, 'leave_type_id' => $typeId, 'start_date' => min($dates), 'end_date' => max($dates),
            'days' => count($dates), 'status' => 'APPROVED', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($dates as $date) {
            foreach (['AM', 'PM'] as $slot) {
                DB::table('leave_request_days')->insert([
                    'leave_request_id' => $requestId, 'employee_id' => $employee->id, 'date' => $date, 'slot' => $slot, 'blocking' => 1,
                ]);
            }
        }
    }
}
