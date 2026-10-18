<?php

namespace App\Services\Reports\Definitions;

use App\Models\User;
use App\Services\Reports\ReportFilters;
use Illuminate\Support\Collection;

class LateAttendanceReport extends AttendanceReport
{
    public function key(): string
    {
        return 'attendance.late';
    }

    public function title(): string
    {
        return 'Late arrivals';
    }

    public function description(): string
    {
        return 'Days where check-in was after the grace period, with minutes late measured against the frozen schedule.';
    }

    public function filters(): array
    {
        return ['from', 'to', 'department_id', 'employee_id'];
    }

    public function columns(): array
    {
        return ['date' => 'Date', 'employee_number' => 'Employee no.', 'employee' => 'Employee', 'department' => 'Department',
            'expected_start' => 'Scheduled start', 'check_in' => 'Check in', 'late_minutes' => 'Late (min)'];
    }

    public function numericColumns(): array
    {
        return ['late_minutes'];
    }

    public function rows(ReportFilters $f, User $viewer): Collection
    {
        return $this->base($f, $viewer)->where('a.is_late', true)->orderByDesc('a.late_minutes')->orderBy('a.work_date')
            ->get(['a.work_date', 'e.employee_number', 'e.full_name', 'dep.name as department', 's.expected_start_at', 'a.check_in_at', 'a.late_minutes'])
            ->map(fn ($r) => [
                'date' => $r->work_date, 'employee_number' => $r->employee_number, 'employee' => $r->full_name, 'department' => $r->department,
                'expected_start' => $this->ctx->time($r->expected_start_at), 'check_in' => $this->ctx->time($r->check_in_at), 'late_minutes' => (int) $r->late_minutes,
            ]);
    }

    public function totals(Collection $rows): ?array
    {
        return ['employee' => $rows->count().' late arrivals', 'late_minutes' => (int) $rows->sum('late_minutes')];
    }
}
