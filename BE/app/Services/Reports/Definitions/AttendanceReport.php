<?php

namespace App\Services\Reports\Definitions;

use App\Models\Employee;
use App\Models\User;
use App\Services\Reports\Report;
use App\Services\Reports\ReportFilters;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Shared base for attendance reports. Attendance rows are filtered by work date and by the placement
 * (department) the employee had *on that date*, using the frozen schedule for shift and location names.
 */
abstract class AttendanceReport extends Report
{
    public function group(): string
    {
        return 'attendance';
    }

    public function permissions(): array
    {
        return ['attendance.view', 'attendance.view_team'];
    }

    protected function base(ReportFilters $f, User $viewer): Builder
    {
        $q = DB::table('attendance as a')
            ->join('employees as e', 'e.id', '=', 'a.employee_id')
            ->leftJoin('attendance_schedules as s', 's.id', '=', 'a.attendance_schedule_id')
            ->whereIn('a.employee_id', $this->ctx->employeeIds($viewer, 'attendance.view', 'attendance.view_team'))
            ->whereBetween('a.work_date', [$f->from, $f->to]);
        $this->ctx->joinPlacement($q, 'a.employee_id', 'a.work_date');

        if ($f->departmentId) {
            $q->where('ea.department_id', $f->departmentId);
        }
        if ($f->employeeId) {
            $q->where('a.employee_id', $f->employeeId);
        }

        return $q;
    }
}
