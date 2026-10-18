<?php

namespace App\Services\Reports\Definitions;

use App\Enums\LeaveStatus;
use App\Models\User;
use App\Services\Reports\Report;
use App\Services\Reports\ReportFilters;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Approved leave days actually taken inside the window, per employee and leave type (counted per calendar slot). */
class LeaveUsageReport extends Report
{
    public function key(): string
    {
        return 'leave.usage';
    }

    public function title(): string
    {
        return 'Leave usage';
    }

    public function group(): string
    {
        return 'leave';
    }

    public function description(): string
    {
        return 'Approved leave days taken in the period, per employee and leave type.';
    }

    public function permissions(): array
    {
        return ['leave.view', 'leave.view_team'];
    }

    public function filters(): array
    {
        return ['from', 'to', 'department_id', 'employee_id', 'leave_type_id'];
    }

    public function columns(): array
    {
        return ['employee_number' => 'Employee no.', 'employee' => 'Employee', 'department' => 'Department', 'leave_type' => 'Leave type', 'paid' => 'Paid', 'days' => 'Days'];
    }

    public function numericColumns(): array
    {
        return ['days'];
    }

    public function rows(ReportFilters $f, User $viewer): Collection
    {
        $q = DB::table('leave_request_days as d')
            ->join('leave_requests as r', 'r.id', '=', 'd.leave_request_id')
            ->join('leave_types as t', 't.id', '=', 'r.leave_type_id')
            ->join('employees as e', 'e.id', '=', 'd.employee_id')
            ->where('r.status', LeaveStatus::Approved->value)
            ->whereBetween('d.date', [$f->from, $f->to])
            ->whereIn('d.employee_id', $this->ctx->employeeIds($viewer, 'leave.view', 'leave.view_team'));
        $this->ctx->joinPlacement($q, 'd.employee_id', 'd.date');

        if ($f->departmentId) {
            $q->where('ea.department_id', $f->departmentId);
        }
        if ($f->employeeId) {
            $q->where('d.employee_id', $f->employeeId);
        }
        if ($f->leaveTypeId) {
            $q->where('r.leave_type_id', $f->leaveTypeId);
        }

        return $q->groupBy('e.id', 'e.employee_number', 'e.full_name', 't.id', 't.name', 't.is_paid')->orderBy('e.full_name')->orderBy('t.name')
            ->selectRaw('e.employee_number, e.full_name, MAX(dep.name) as department, t.name as leave_type, t.is_paid, COUNT(*) / 2 as days')
            ->get()
            ->map(fn ($r) => [
                'employee_number' => $r->employee_number, 'employee' => $r->full_name, 'department' => $r->department, 'leave_type' => $r->leave_type,
                'paid' => $r->is_paid ? 'Paid' : 'Unpaid', 'days' => number_format((float) $r->days, 1, '.', ''),
            ]);
    }

    public function totals(Collection $rows): ?array
    {
        return ['employee' => 'Total', 'days' => number_format((float) $rows->sum('days'), 1, '.', '')];
    }
}
