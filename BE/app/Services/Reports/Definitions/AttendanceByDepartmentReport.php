<?php

namespace App\Services\Reports\Definitions;

use App\Models\User;
use App\Services\Reports\ReportFilters;
use Illuminate\Support\Collection;

class AttendanceByDepartmentReport extends AttendanceReport
{
    public function key(): string
    {
        return 'attendance.by_department';
    }

    public function title(): string
    {
        return 'Attendance by department';
    }

    public function description(): string
    {
        return 'Check-ins, late rate and hours per department, using each person\'s department on the day.';
    }

    public function filters(): array
    {
        return ['from', 'to', 'department_id'];
    }

    public function columns(): array
    {
        return ['department' => 'Department', 'employees' => 'Employees', 'check_ins' => 'Check-ins', 'late' => 'Late', 'late_rate' => 'Late rate', 'worked' => 'Worked (h:mm)'];
    }

    public function numericColumns(): array
    {
        return ['employees', 'check_ins', 'late', 'late_rate', 'worked'];
    }

    public function rows(ReportFilters $f, User $viewer): Collection
    {
        return $this->base($f, $viewer)->groupBy('ea.department_id', 'dep.name')->orderBy('dep.name')
            ->selectRaw('COALESCE(dep.name, \'Unassigned\') as department, COUNT(DISTINCT a.employee_id) as employees, COUNT(a.id) as check_ins,
                SUM(a.is_late) as late, SUM(a.worked_minutes) as worked')
            ->get()
            ->map(fn ($r) => [
                'department' => $r->department, 'employees' => (int) $r->employees, 'check_ins' => (int) $r->check_ins, 'late' => (int) $r->late,
                'late_rate' => $r->check_ins > 0 ? round(100 * $r->late / $r->check_ins, 1).'%' : '0%', 'worked' => $this->ctx->hours($r->worked),
            ]);
    }
}
