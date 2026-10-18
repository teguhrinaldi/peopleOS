<?php

namespace App\Services\Reports\Definitions;

use App\Models\User;
use App\Services\Reports\ReportFilters;
use Illuminate\Support\Collection;

class DailyAttendanceReport extends AttendanceReport
{
    public function key(): string
    {
        return 'attendance.daily';
    }

    public function title(): string
    {
        return 'Daily attendance';
    }

    public function description(): string
    {
        return 'Every check-in and check-out in the period, one row per employee per day.';
    }

    public function filters(): array
    {
        return ['from', 'to', 'department_id', 'employee_id', 'status'];
    }

    public function columns(): array
    {
        return [
            'date' => 'Date', 'employee_number' => 'Employee no.', 'employee' => 'Employee', 'department' => 'Department',
            'shift' => 'Shift', 'check_in' => 'Check in', 'check_out' => 'Check out', 'worked' => 'Worked (h:mm)',
            'late_minutes' => 'Late (min)', 'early_leave_minutes' => 'Left early (min)', 'status' => 'Status',
        ];
    }

    public function numericColumns(): array
    {
        return ['worked', 'late_minutes', 'early_leave_minutes'];
    }

    public function rows(ReportFilters $f, User $viewer): Collection
    {
        $q = $this->base($f, $viewer)->orderBy('a.work_date')->orderBy('e.full_name');
        if ($f->status === 'LATE') {
            $q->where('a.is_late', true);
        } elseif ($f->status) {
            $q->where('a.status', $f->status);
        }

        return $q->get(['a.work_date', 'e.employee_number', 'e.full_name', 'dep.name as department', 's.shift_name', 'a.check_in_at', 'a.check_out_at',
            'a.worked_minutes', 'a.late_minutes', 'a.early_leave_minutes', 'a.status'])
            ->map(fn ($r) => [
                'date' => $r->work_date, 'employee_number' => $r->employee_number, 'employee' => $r->full_name, 'department' => $r->department,
                'shift' => $r->shift_name, 'check_in' => $this->ctx->time($r->check_in_at), 'check_out' => $this->ctx->time($r->check_out_at),
                'worked' => $this->ctx->hours($r->worked_minutes), 'late_minutes' => (int) $r->late_minutes,
                'early_leave_minutes' => (int) $r->early_leave_minutes, 'status' => $r->status,
            ]);
    }
}
