<?php

namespace App\Services\Reports\Definitions;

use App\Models\Employee;
use App\Models\User;
use App\Services\Reports\ReportFilters;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AttendanceSummaryReport extends AttendanceReport
{
    public function key(): string
    {
        return 'attendance.by_employee';
    }

    public function title(): string
    {
        return 'Attendance by employee';
    }

    public function description(): string
    {
        return 'Monthly style summary per employee: days present, late days, hours worked and approved overtime.';
    }

    public function filters(): array
    {
        return ['from', 'to', 'department_id', 'employee_id'];
    }

    public function columns(): array
    {
        return ['employee_number' => 'Employee no.', 'employee' => 'Employee', 'department' => 'Department', 'days_present' => 'Days present',
            'late_days' => 'Late days', 'late_minutes' => 'Late (min)', 'early_days' => 'Left early', 'worked' => 'Worked (h:mm)', 'overtime' => 'Approved overtime (h:mm)'];
    }

    public function numericColumns(): array
    {
        return ['days_present', 'late_days', 'late_minutes', 'early_days', 'worked', 'overtime'];
    }

    public function rows(ReportFilters $f, User $viewer): Collection
    {
        $overtime = DB::table('overtime_requests')->where('status', 'APPROVED')->whereBetween('work_date', [$f->from, $f->to])
            ->groupBy('employee_id')->selectRaw('employee_id, SUM(approved_minutes) as minutes')->pluck('minutes', 'employee_id');

        // Department comes from the employee's latest placement inside the window (one value per person).
        return $this->base($f, $viewer)->groupBy('e.id', 'e.employee_number', 'e.full_name')->orderBy('e.full_name')
            ->selectRaw('e.id, e.employee_number, e.full_name, MAX(dep.name) as department, COUNT(a.id) as days_present, SUM(a.is_late) as late_days,
                SUM(a.late_minutes) as late_minutes, SUM(a.is_early_leave) as early_days, SUM(a.worked_minutes) as worked')
            ->get()
            ->map(fn ($r) => [
                'employee_number' => $r->employee_number, 'employee' => $r->full_name, 'department' => $r->department, 'days_present' => (int) $r->days_present,
                'late_days' => (int) $r->late_days, 'late_minutes' => (int) $r->late_minutes, 'early_days' => (int) $r->early_days,
                'worked' => $this->ctx->hours($r->worked), 'overtime' => $this->ctx->hours($overtime[$r->id] ?? 0),
            ]);
    }
}
