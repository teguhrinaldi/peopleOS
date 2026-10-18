<?php

namespace App\Services\Admin;

use App\Enums\AttendanceStatus;
use App\Enums\EmployeeStatus;
use App\Enums\LeaveStatus;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\LeaveRequestDay;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * THE definition of attendance numbers shown on the dashboard (KPI cards, donut and the trend chart all read from here).
 * Dates are company-local business dates (`attendance.work_date`, written by the attendance domain from the company
 * time zone), so a check-in at 23:30 Asia/Jakarta belongs to that local day even though its UTC date may differ.
 *
 *  - PRESENT  an employee in scope has an attendance record for the date with a check-in and a status other than REJECTED.
 *  - LATE     present AND `is_late` (set by the attendance domain against the frozen schedule and its grace period).
 *  - ON TIME  present AND NOT late.  On time + Late = Present, always.
 *  - EXPECTED an ACTIVE employee in scope who joined on/before the date, was not terminated before it, has an assignment
 *             with a shift on the date, on a company working day (holiday / default calendar) and not on approved FULL-day leave.
 *  - ABSENT   expected AND not present, for days that are already over (before today). Rest days and holidays expect nobody,
 *             so they are "no data", never zero absences. Today has no absences yet, only people NOT CHECKED IN YET.
 *  - NOT YET  (today only) expected and not present so far.
 *
 * Known limit: employee status is not dated, so INACTIVE/PENDING people are never expected on any day, past ones included.
 */
class AttendanceMetrics
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly CompanyCalendar $calendar,
    ) {}

    /**
     * One entry per day from $from to $to inclusive (days after today are not listed).
     *
     * @param  Builder<Employee>  $people  already scoped to what the viewer may see
     * @return list<array<string, mixed>>
     */
    public function series(Builder $people, string $from, string $to): array
    {
        $today = $this->context->today()->toDateString();
        $to = min($to, $today);
        if ($from > $to) {
            return [];
        }

        $ids = (clone $people)->reorder()->select('employees.id');
        $employees = Employee::query()->whereIn('id', $ids)->get(['id', 'status', 'join_date', 'terminated_at']);
        $present = $this->presentByDate($ids, $from, $to);
        $shifted = $this->shiftAssignments($ids, $from, $to);
        $onLeave = $this->fullDayLeave($ids, $from, $to);
        $working = $this->calendar->workingDays($from, $to);

        $out = [];
        for ($day = CarbonImmutable::parse($from); $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $date = $day->toDateString();
            $isToday = $date === $today;
            $here = $present[$date] ?? [];

            $expected = [];
            if ($working[$date] ?? false) {
                foreach ($employees as $e) {
                    if ($this->isExpected($e, $date, $shifted[$e->id] ?? [], isset($onLeave[$date][$e->id]))) {
                        $expected[$e->id] = true;
                    }
                }
            }
            $late = count(array_filter($here));
            $missing = count(array_diff_key($expected, $here));

            $out[] = [
                'date' => $date,
                'label' => $day->format('D'),
                'dayLabel' => $day->format('D j M'),
                'today' => $isToday,
                'working' => (bool) ($working[$date] ?? false),
                'expected' => count($expected),
                'present' => count($here),
                'ontime' => count($here) - $late,
                'late' => $late,
                'absent' => $isToday || ! ($working[$date] ?? false) ? null : $missing,
                'notYet' => $isToday ? $missing : null,
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> the same shape as one series() entry */
    public function forDate(Builder $people, string $date): array
    {
        return $this->series($people, $date, $date)[0] ?? ['date' => $date, 'working' => false, 'expected' => 0, 'present' => 0, 'ontime' => 0, 'late' => 0, 'absent' => null, 'notYet' => null, 'today' => false];
    }

    /** Totals for the chart header: sums over the listed days (absences count closed working days only). @param list<array<string, mixed>> $days */
    public function totals(array $days): array
    {
        return [
            'present' => array_sum(array_column($days, 'present')),
            'ontime' => array_sum(array_column($days, 'ontime')),
            'late' => array_sum(array_column($days, 'late')),
            'absent' => array_sum(array_map(fn ($d) => (int) $d['absent'], $days)),
            'workingDays' => count(array_filter($days, fn ($d) => $d['working'])),
        ];
    }

    private function isExpected(Employee $e, string $date, array $assignments, bool $fullLeave): bool
    {
        if ($e->status !== EmployeeStatus::Active || $fullLeave) {
            return false;
        }
        if ($e->join_date && $e->join_date->toDateString() > $date) {
            return false;
        }
        if ($e->terminated_at && $e->terminated_at->toDateString() < $date) {
            return false;
        }
        foreach ($assignments as [$start, $end]) {
            if ($start <= $date && ($end === null || $end >= $date)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, array<int, bool>> date => [employee_id => is_late] */
    private function presentByDate(mixed $ids, string $from, string $to): array
    {
        $out = [];
        Attendance::query()
            ->whereIn('employee_id', $ids)
            ->whereBetween('work_date', [$from, $to])
            ->whereNotNull('check_in_at')
            ->where('status', '!=', AttendanceStatus::Rejected->value)
            ->get(['employee_id', 'work_date', 'is_late'])
            ->each(function ($r) use (&$out) {
                $out[substr((string) $r->getRawOriginal('work_date'), 0, 10)][$r->employee_id] = (bool) $r->is_late;
            });

        return $out;
    }

    /** @return array<int, list<array{0: string, 1: ?string}>> employee_id => [[effective_from, effective_to], ...] for assignments with a shift */
    private function shiftAssignments(mixed $ids, string $from, string $to): array
    {
        $out = [];
        EmployeeAssignment::query()->whereIn('employee_id', $ids)->whereNotNull('shift_id')
            ->where('effective_from', '<=', $to)->where(fn ($w) => $w->whereNull('effective_to')->orWhere('effective_to', '>=', $from))
            ->get(['employee_id', 'effective_from', 'effective_to'])
            ->each(function ($a) use (&$out) {
                $out[$a->employee_id][] = [$a->effective_from->toDateString(), $a->effective_to?->toDateString()];
            });

        return $out;
    }

    /** @return array<string, array<int, true>> date => [employee_id => true] for approved leave covering both half-day slots */
    private function fullDayLeave(mixed $ids, string $from, string $to): array
    {
        $out = [];
        LeaveRequestDay::query()
            ->join('leave_requests as lr', 'lr.id', '=', 'leave_request_days.leave_request_id')
            ->where('lr.status', LeaveStatus::Approved->value)
            ->whereBetween('leave_request_days.date', [$from, $to])
            ->whereIn('leave_request_days.employee_id', $ids)
            ->groupBy('leave_request_days.employee_id', 'leave_request_days.date')
            ->havingRaw('count(distinct leave_request_days.slot) >= 2')
            ->selectRaw('leave_request_days.employee_id, leave_request_days.date')
            ->get()
            ->each(function ($r) use (&$out) {
                $out[substr((string) $r->getRawOriginal('date'), 0, 10)][$r->employee_id] = true;
            });

        return $out;
    }
}
