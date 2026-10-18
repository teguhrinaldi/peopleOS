<?php

namespace App\Services\Reports\Definitions;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\ScheduleService;
use App\Services\Leave\LeaveLedger;
use App\Services\Reports\ReportContext;
use App\Services\Reports\ReportFilters;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AbsentReport extends AttendanceReport
{
    public function __construct(ReportContext $ctx, private readonly ScheduleService $schedules, private readonly LeaveLedger $leave)
    {
        parent::__construct($ctx);
    }

    public function key(): string
    {
        return 'attendance.absent';
    }

    public function title(): string
    {
        return 'Absent employees';
    }

    public function description(): string
    {
        return 'Scheduled working days (before today) with no attendance and no approved leave. Window limited to 93 days.';
    }

    public function filters(): array
    {
        return ['from', 'to', 'department_id', 'employee_id'];
    }

    public function columns(): array
    {
        return ['date' => 'Date', 'employee_number' => 'Employee no.', 'employee' => 'Employee', 'department' => 'Department', 'shift' => 'Shift', 'note' => 'Note'];
    }

    public function rows(ReportFilters $f, User $viewer): Collection
    {
        $today = $this->ctx->company->today();
        $to = CarbonImmutable::parse($f->to)->min($today->subDay());
        $from = CarbonImmutable::parse($f->from)->max($to->subDays(92));
        if ($from->greaterThan($to)) {
            return collect();
        }

        $people = Employee::query()->whereIn('id', $this->ctx->employeeIds($viewer, 'attendance.view', 'attendance.view_team'))
            ->whereIn('status', [EmployeeStatus::Active->value, EmployeeStatus::Terminated->value])
            ->when($f->employeeId, fn ($q) => $q->where('id', $f->employeeId))->orderBy('full_name')->get();

        $present = DB::table('attendance')->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('employee_id', $people->pluck('id'))->get(['employee_id', 'work_date'])
            ->mapWithKeys(fn ($r) => [$r->employee_id.'|'.substr((string) $r->work_date, 0, 10) => true]);
        $leave = $this->leave->coverageMap($people->pluck('id')->all(), $from->toDateString(), $to->toDateString());

        $rows = collect();
        foreach ($people as $employee) {
            for ($d = $from; $d->lessThanOrEqualTo($to); $d = $d->addDay()) {
                $date = $d->toDateString();
                $key = $employee->id.'|'.$date;
                if ($date < $employee->join_date->toDateString() || ($employee->terminated_at && $date > $employee->terminated_at->toDateString())) {
                    continue;
                }
                if (isset($present[$key]) || ($leave[$key] ?? null) === 'FULL') {
                    continue;
                }
                $schedule = $this->schedules->peek($employee, $date);
                if (! $schedule->is_working_day) {
                    continue;
                }
                $assignment = $employee->assignmentOn($date);
                if ($f->departmentId && $assignment?->department_id !== $f->departmentId) {
                    continue;
                }
                $rows->push([
                    'date' => $date, 'employee_number' => $employee->employee_number, 'employee' => $employee->full_name,
                    'department' => $assignment?->department?->name, 'shift' => $schedule->shift_name,
                    'note' => ($leave[$key] ?? null) ? 'Half-day leave only' : '',
                ]);
            }
        }

        return $rows->sortBy([['date', 'asc'], ['employee', 'asc']])->values();
    }

    public function totals(Collection $rows): ?array
    {
        return ['employee' => $rows->count().' absences'];
    }
}
