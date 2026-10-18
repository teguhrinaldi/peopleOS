<?php

namespace App\Services\Admin;

use App\Enums\EmployeeStatus;
use App\Enums\LeaveStatus;
use App\Enums\PayrollStatus;
use App\Enums\RequestStatus;
use App\Models\Activity;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\OvertimeRequest;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Services\AccessScope;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Numbers for the admin dashboard. Every figure comes from aggregate queries on the caller's
 * AccessScope (HR/Admin: everyone, Manager: direct reports) and the company-local calendar day.
 */
class DashboardService
{
    public const TREND_DAYS = 7;

    public const TREND_RANGES = [7, 14, 30];

    public function __construct(
        private readonly AccessScope $scope,
        private readonly CompanyContext $context,
        private readonly ActivityPresenter $presenter,
        private readonly CompanyCalendar $calendar,
        private readonly DocumentDesk $documents,
        private readonly AttendanceMetrics $metrics,
    ) {}

    /** @return array<string, mixed> */
    public function forUser(User $user): array
    {
        $people = $this->scope->employees($user, 'employee.view', 'employee.view_team', false);
        $today = $this->context->today();
        $todayDate = $today->toDateString();

        $byStatus = (clone $people)->reorder()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $active = (int) ($byStatus[EmployeeStatus::Active->value] ?? 0);
        $total = (int) $byStatus->except(EmployeeStatus::Terminated->value)->sum();

        $workingDay = $this->calendar->isWorkingDay($todayDate);
        $leave = $this->leaveToday($people, $todayDate);
        // One shared definition (AttendanceMetrics) feeds the KPI cards, the donut and the trend chart.
        $trend = $this->trend($user, self::TREND_DAYS, $people);
        $now = end($trend['days']) ?: ['present' => 0, 'late' => 0, 'expected' => 0, 'notYet' => 0];
        $present = $now['present'];
        $late = $now['late'];
        $expected = $now['expected'];
        $notYet = (int) ($now['notYet'] ?? 0);

        return [
            'greeting' => $this->greeting($user),
            'date' => $today,
            'scope' => $user->hasPermission('employee.view') ? 'company' : 'team',
            'workingDay' => $workingDay,
            'kpis' => [
                'total' => $total,
                'active' => $active,
                'pending' => (int) ($byStatus[EmployeeStatus::Pending->value] ?? 0),
                'present' => $present,
                'late' => $late,
                'expected' => $expected,
                'notYet' => $notYet,
                'presentRate' => $expected > 0 ? round($present / $expected * 100, 1) : null,
                'lateRate' => $present > 0 ? round($late / $present * 100, 1) : null,
                'onLeave' => $leave['total'],
                'onLeaveHalf' => $leave['half'],
            ],
            'donut' => [
                ['label' => 'On time', 'value' => max(0, $present - $late), 'color' => 'var(--primary)'],
                ['label' => 'Late', 'value' => $late, 'color' => 'var(--warning)'],
                ['label' => 'Not checked in', 'value' => $notYet, 'color' => 'var(--danger)'],
                ['label' => 'On full-day leave', 'value' => $leave['fullExpected'], 'color' => 'var(--tone-purple)'],
            ],
            'trend' => $trend['days'],
            'trendMeta' => $trend['meta'],
            'approvals' => $this->approvals($user),
            'upcomingLeave' => $user->hasAnyPermission('leave.view', 'leave.view_team', 'leave.approve', 'leave.approve_team')
                ? $this->upcomingLeave($user, $today) : null,
            'activity' => $user->hasPermission('activity.view') ? $this->recentActivity() : null,
            'payroll' => $user->hasPermission('payroll.view') ? $this->payrollStatus() : null,
            'documents' => $this->documents->expiryCounts($user),
        ];
    }

    /** Latest payroll period that is not cancelled, with its state and net total from the run snapshot. @return array<string, mixed>|null */
    private function payrollStatus(): ?array
    {
        $period = PayrollPeriod::query()->where('company_id', $this->context->id())
            ->where('status', '!=', PayrollStatus::Cancelled->value)->with('run')->orderByDesc('start_date')->orderByDesc('id')->first();
        if (! $period) {
            return ['none' => true];
        }
        $run = $period->run;

        return [
            'none' => false, 'name' => $period->name, 'status' => $period->status,
            'net' => $run?->generated_at ? PayrollDesk::money($run->total_net, $run) : null,
            'employees' => $run?->generated_at ? $run->employee_count : null,
            'url' => route('admin.payroll.show', $period),
        ];
    }

    private function greeting(User $user): string
    {
        $hour = $this->context->now()->hour;
        $part = $hour < 12 ? 'morning' : ($hour < 18 ? 'afternoon' : 'evening');

        return "Good {$part}, ".strtok($user->name, ' ');
    }

    /**
     * Attendance trend for the last $days days ending today (company time zone): per-day numbers plus totals.
     * A failed aggregation is reported as error=true so the chart can show an error state instead of fake zeros.
     *
     * @return array{days: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function trend(User $user, int $days = self::TREND_DAYS, ?Builder $people = null): array
    {
        $days = in_array($days, self::TREND_RANGES, true) ? $days : self::TREND_DAYS;
        $people ??= $this->scope->employees($user, 'employee.view', 'employee.view_team', false);
        $today = $this->context->today();
        $from = $today->subDays($days - 1);

        try {
            $series = $this->metrics->series($people, $from->toDateString(), $today->toDateString());
            $error = false;
        } catch (Throwable $e) {
            report($e);
            $series = [];
            $error = true;
        }

        return [
            'days' => $series,
            'meta' => [
                'error' => $error, 'range' => $days, 'from' => $from, 'to' => $today, 'timezone' => $this->context->timezone(),
                'totals' => $this->metrics->totals($series),
                'hasData' => (bool) array_filter($series, fn ($d) => $d['expected'] > 0 || $d['present'] > 0),
            ],
        ];
    }

    /** @return list<array<string, mixed>> only the queues this user may act on */
    private function approvals(User $user): array
    {
        $cards = [];

        if ($user->hasAnyPermission('leave.approve', 'leave.approve_team')) {
            $ids = $this->notMine($user, $this->scope->employees($user, 'leave.approve', 'leave.approve_team', false));
            $cards[] = [
                'label' => 'Leave requests', 'icon' => 'calendar-days', 'tone' => 'primary', 'url' => route('admin.leave-requests'),
                'count' => LeaveRequest::query()->where('status', LeaveStatus::Pending->value)->whereIn('employee_id', $ids)->count(),
            ];
        }

        if ($user->hasAnyPermission('attendance.correct', 'attendance.approve_team')) {
            $ids = $this->notMine($user, $this->scope->employees($user, 'attendance.correct', 'attendance.approve_team', false));
            $cards[] = [
                'label' => 'Attendance corrections', 'icon' => 'clipboard-check', 'tone' => 'warning', 'url' => route('admin.attendance.corrections'),
                'count' => AttendanceCorrection::query()->where('status', RequestStatus::Pending->value)->whereIn('employee_id', $ids)->count(),
            ];
        }

        if ($user->hasAnyPermission('overtime.approve', 'overtime.approve_team')) {
            $ids = $this->notMine($user, $this->scope->employees($user, 'overtime.approve', 'overtime.approve_team', false));
            $cards[] = [
                'label' => 'Overtime requests', 'icon' => 'timer', 'tone' => 'primary', 'url' => route('admin.overtime'),
                'count' => OvertimeRequest::query()->where('status', RequestStatus::Pending->value)->whereIn('employee_id', $ids)->count(),
            ];
        }

        if ($user->hasPermission('attendance.review')) {
            $ids = $this->notMine($user, $this->scope->employees($user, 'attendance.view', null, false));
            $cards[] = [
                'label' => 'Flagged attendance', 'icon' => 'flag', 'tone' => 'danger', 'url' => route('admin.attendance', ['review' => 1]),
                'count' => Attendance::query()->where('needs_review', true)->whereIn('employee_id', $ids)->count(),
            ];
        }

        return $cards;
    }

    /** @return Collection<int, array<string, mixed>> */
    private function recentActivity(): Collection
    {
        return $this->presenter->present(Activity::query()->with('actor')->latest('id')->limit(8)->get());
    }

    /** Employee ids of a scope query, minus the user's own record. */
    private function notMine(User $user, Builder $scope): Builder
    {
        return $scope->when($user->employee, fn (Builder $q, $me) => $q->whereKeyNot($me->id))->select('employees.id');
    }

    /**
     * Approved leave today for employees in scope (one grouped query over leave_request_days).
     *
     * @return array{total: int, half: int, fullExpected: int}
     */
    private function leaveToday(Builder $people, string $date): array
    {
        $slots = LeaveRequestDay::query()
            ->join('leave_requests as lr', 'lr.id', '=', 'leave_request_days.leave_request_id')
            ->where('lr.status', LeaveStatus::Approved->value)
            ->whereDate('leave_request_days.date', $date)
            ->whereIn('leave_request_days.employee_id', (clone $people)->reorder()->select('employees.id'))
            ->groupBy('leave_request_days.employee_id')
            ->selectRaw('leave_request_days.employee_id, count(distinct leave_request_days.slot) as n')
            ->pluck('n', 'employee_id');

        $full = $slots->filter(fn ($n) => (int) $n >= 2)->keys();
        $fullExpected = $full->isEmpty() ? 0 : (clone $people)->reorder()
            ->where('status', EmployeeStatus::Active->value)
            ->whereIn('employees.id', $full)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('employee_assignments as ea')
                ->whereColumn('ea.employee_id', 'employees.id')->whereNotNull('ea.shift_id')
                ->where('ea.effective_from', '<=', $date)
                ->where(fn ($w) => $w->whereNull('ea.effective_to')->orWhere('ea.effective_to', '>=', $date)))
            ->count();

        return ['total' => $slots->count(), 'half' => $slots->count() - $full->count(), 'fullExpected' => $fullExpected];
    }

    /** Approved and pending leave starting within the next 14 days (or running now) for people this user may see. */
    private function upcomingLeave(User $user, CarbonImmutable $today): Collection
    {
        $all = $user->hasPermission('leave.view') ? 'leave.view' : 'leave.approve';
        $team = $user->hasPermission('leave.view_team') ? 'leave.view_team' : 'leave.approve_team';

        return LeaveRequest::query()
            ->whereIn('employee_id', $this->scope->employees($user, $all, $team, false)->select('employees.id'))
            ->whereIn('status', [LeaveStatus::Pending->value, LeaveStatus::Approved->value])
            ->where('end_date', '>=', $today->toDateString())
            ->where('start_date', '<=', $today->addDays(14)->toDateString())
            ->with(['employee:id,full_name,photo_path', 'leaveType:id,name,color'])
            ->orderBy('start_date')->limit(6)->get();
    }
}
