<?php

namespace App\Services\Reports\Definitions;

use App\Enums\LeaveStatus;
use App\Models\User;
use App\Services\Reports\Report;
use App\Services\Reports\ReportFilters;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Leave requests overlapping the window, filterable by status (PENDING, APPROVED, REJECTED, CANCELLED). Drafts are never listed. */
class LeaveRequestsReport extends Report
{
    public function key(): string
    {
        return 'leave.requests';
    }

    public function title(): string
    {
        return 'Leave requests';
    }

    public function group(): string
    {
        return 'leave';
    }

    public function description(): string
    {
        return 'Pending, approved and rejected requests with reviewer and decision notes.';
    }

    public function permissions(): array
    {
        return ['leave.view', 'leave.view_team'];
    }

    public function filters(): array
    {
        return ['from', 'to', 'department_id', 'employee_id', 'status', 'leave_type_id'];
    }

    public function columns(): array
    {
        return ['employee_number' => 'Employee no.', 'employee' => 'Employee', 'department' => 'Department', 'leave_type' => 'Leave type', 'start' => 'From', 'end' => 'To',
            'days' => 'Days', 'status' => 'Status', 'submitted' => 'Submitted', 'reviewer' => 'Reviewed by', 'note' => 'Decision note'];
    }

    public function numericColumns(): array
    {
        return ['days'];
    }

    public function rows(ReportFilters $f, User $viewer): Collection
    {
        $q = DB::table('leave_requests as r')
            ->join('leave_types as t', 't.id', '=', 'r.leave_type_id')
            ->join('employees as e', 'e.id', '=', 'r.employee_id')
            ->leftJoin('users as u', 'u.id', '=', 'r.reviewed_by')
            ->where('r.status', '!=', LeaveStatus::Draft->value)
            ->where('r.end_date', '>=', $f->from)->where('r.start_date', '<=', $f->to)
            ->whereIn('r.employee_id', $this->ctx->employeeIds($viewer, 'leave.view', 'leave.view_team'));
        $this->ctx->joinPlacement($q, 'r.employee_id', 'r.start_date');

        if ($f->departmentId) {
            $q->where('ea.department_id', $f->departmentId);
        }
        if ($f->employeeId) {
            $q->where('r.employee_id', $f->employeeId);
        }
        if ($f->status) {
            $q->where('r.status', $f->status);
        }
        if ($f->leaveTypeId) {
            $q->where('r.leave_type_id', $f->leaveTypeId);
        }

        return $q->orderByDesc('r.start_date')->orderBy('e.full_name')
            ->get(['e.employee_number', 'e.full_name', 'dep.name as department', 't.name as leave_type', 'r.start_date', 'r.end_date', 'r.days', 'r.status',
                'r.submitted_at', 'u.name as reviewer', 'r.review_note'])
            ->map(fn ($r) => [
                'employee_number' => $r->employee_number, 'employee' => $r->full_name, 'department' => $r->department, 'leave_type' => $r->leave_type,
                'start' => $r->start_date, 'end' => $r->end_date, 'days' => number_format((float) $r->days, 1, '.', ''), 'status' => $r->status,
                'submitted' => $this->ctx->dateTime($r->submitted_at), 'reviewer' => $r->reviewer, 'note' => $r->review_note,
            ]);
    }

    public function totals(Collection $rows): ?array
    {
        return ['employee' => $rows->count().' requests', 'days' => number_format((float) $rows->sum('days'), 1, '.', '')];
    }
}
