<?php

namespace App\Services\Payroll;

use App\Enums\AttendanceStatus;
use App\Enums\EmployeeStatus;
use App\Enums\RequestStatus;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeSalaryComponent;
use App\Models\OvertimeRequest;
use App\Models\PayrollPeriod;
use App\Services\Leave\LeaveLedger;
use App\Services\Payroll\Calculation\ComponentValue;
use App\Services\Payroll\Calculation\PayrollInput;
use App\Support\CompanyContext;
use Illuminate\Support\Collection;

/**
 * Reads the database and turns it into calculator inputs. The ONLY payroll class that knows where
 * attendance, overtime, leave and salary values live.
 *
 * Who is paid: employees employed during the period, i.e. join_date <= period end AND (not terminated, or
 * terminated_at >= period start). Status ACTIVE, or TERMINATED with a termination date (historical processing
 * of periods that overlap their employment; never later ones). INACTIVE / PENDING people are not paid and are
 * reported in the run summary instead of being dropped silently.
 *
 * Salary values: the employee's effective-dated components in force on the REFERENCE DATE = the period end,
 * or the termination date when that is earlier. A change effective mid-period therefore applies to the whole
 * period in which it is in force on that date (no pro-rating; documented in docs/payroll.md).
 */
class PayrollInputCollector
{
    public function __construct(
        private readonly LeaveLedger $leave,
        private readonly CompanyContext $context,
    ) {}

    /**
     * @return array{employees: Collection<int, Employee>, skipped: list<array<string, mixed>>}
     */
    public function eligible(PayrollPeriod $period): array
    {
        $from = $period->start_date->toDateString();
        $to = $period->end_date->toDateString();

        $candidates = Employee::query()
            ->where('company_id', $this->context->id())
            ->whereDate('join_date', '<=', $to)
            ->where(fn ($q) => $q->whereNull('terminated_at')->orWhereDate('terminated_at', '>=', $from))
            ->orderBy('employee_number')
            ->get();

        $employees = collect();
        $skipped = [];
        foreach ($candidates as $employee) {
            $reason = match (true) {
                $employee->status === EmployeeStatus::Active => null,
                $employee->status === EmployeeStatus::Terminated && $employee->terminated_at !== null => null,
                $employee->status === EmployeeStatus::Inactive => 'EMPLOYEE_INACTIVE',
                $employee->status === EmployeeStatus::Pending => 'EMPLOYEE_PENDING',
                default => 'EMPLOYEE_NOT_ELIGIBLE',
            };

            if ($reason) {
                $skipped[] = $this->skip($employee, $reason);
            } else {
                $employees->push($employee);
            }
        }

        return ['employees' => $employees, 'skipped' => $skipped];
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @return array<int, CollectedInput> keyed by employee id
     */
    public function collect(PayrollPeriod $period, Collection $employees, int $decimals, int $workingDays): array
    {
        $from = $period->start_date->toDateString();
        $to = $period->end_date->toDateString();
        $ids = $employees->pluck('id')->all();

        $present = Attendance::query()
            ->whereIn('employee_id', $ids)
            ->whereBetween('work_date', [$from, $to])
            ->whereNotNull('check_in_at')
            ->where('status', '!=', AttendanceStatus::Rejected->value)
            ->groupBy('employee_id')
            ->selectRaw('employee_id, COUNT(*) AS days')
            ->pluck('days', 'employee_id');

        $overtime = OvertimeRequest::query()
            ->whereIn('employee_id', $ids)
            ->where('status', RequestStatus::Approved->value)
            ->whereBetween('work_date', [$from, $to])
            ->groupBy('employee_id')
            ->selectRaw('employee_id, SUM(COALESCE(approved_minutes, 0)) AS minutes')
            ->pluck('minutes', 'employee_id');

        $salaryRows = EmployeeSalaryComponent::query()
            ->with('component')
            ->whereIn('employee_id', $ids)
            ->whereDate('effective_from', '<=', $to)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from))
            ->get()
            ->groupBy('employee_id');

        $out = [];
        foreach ($employees as $employee) {
            $reference = $employee->terminated_at && $employee->terminated_at->toDateString() < $to
                ? $employee->terminated_at->toDateString()
                : $to;
            $employedFrom = max($from, $employee->join_date->toDateString());

            $components = collect($salaryRows->get($employee->id, []))
                ->filter(fn (EmployeeSalaryComponent $row) => $row->effective_from->toDateString() <= $reference
                    && ($row->effective_to === null || $row->effective_to->toDateString() >= $reference)
                    && $row->component?->is_active)
                ->map(fn (EmployeeSalaryComponent $row) => new ComponentValue(
                    componentId: $row->salary_component_id,
                    code: $row->component->code,
                    name: $row->component->name,
                    kind: $row->component->kind,
                    calculation: $row->component->calculation,
                    isBasic: $row->component->is_basic,
                    amount: $row->amount,
                    rate: $row->rate,
                    sortOrder: $row->component->sort_order,
                ))
                ->values()
                ->all();

            $out[$employee->id] = new CollectedInput(
                employee: $employee,
                referenceDate: $reference,
                snapshot: $this->snapshot($employee, $reference),
                input: new PayrollInput(
                    components: $components,
                    decimals: $decimals,
                    workingDays: $workingDays,
                    presentDays: (int) ($present[$employee->id] ?? 0),
                    overtimeMinutes: (int) ($overtime[$employee->id] ?? 0),
                    unpaidLeaveDays: $this->leave->unpaidDaysBetween($employee, $employedFrom, $reference),
                ),
            );
        }

        return $out;
    }

    /** Who the employee was on a date: the columns frozen on payroll_items. @return array<string, mixed> */
    public function snapshot(Employee $employee, string $date): array
    {
        $assignment = $employee->assignmentOn($date) ?? $employee->assignments()->first();
        $assignment?->loadMissing(['department', 'position', 'branch']);

        return [
            'employee_number' => $employee->employee_number,
            'employee_name' => $employee->full_name,
            'department_name' => $assignment?->department?->name,
            'position_name' => $assignment?->position?->name,
            'branch_name' => $assignment?->branch?->name,
            'employment_type' => $employee->employment_type?->value,
            'join_date' => $employee->join_date?->toDateString(),
            'terminated_at' => $employee->terminated_at?->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    public function skip(Employee $employee, string $reason): array
    {
        return [
            'employee_id' => $employee->id,
            'employee_number' => $employee->employee_number,
            'employee_name' => $employee->full_name,
            'reason' => $reason,
        ];
    }
}
