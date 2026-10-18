<?php

namespace App\Services\Admin;

use App\Models\Activity;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\User;
use App\Services\AccessScope;
use App\Support\CompanyContext;
use Illuminate\Support\Collection;

/** Read models for the employee detail page. Each method serves exactly one tab. */
class EmployeeProfile
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly AccessScope $scope,
        private readonly ActivityPresenter $presenter,
    ) {}

    /** Which tabs this viewer may open, in display order. @return list<array{key: string, label: string, icon: string}> */
    public function tabsFor(User $viewer, Employee $employee): array
    {
        $tabs = [
            ['key' => 'overview', 'label' => 'Overview', 'icon' => 'id-card'],
            ['key' => 'employment', 'label' => 'Employment', 'icon' => 'briefcase'],
        ];

        if ($this->canSeeAttendance($viewer, $employee)) {
            $tabs[] = ['key' => 'attendance', 'label' => 'Attendance', 'icon' => 'clock'];
        }
        if ($this->scope->canAccess($viewer, $employee, 'leave.view', 'leave.view_team', false)) {
            $tabs[] = ['key' => 'leave', 'label' => 'Leave', 'icon' => 'calendar-days'];
        }
        if ($viewer->hasAnyPermission('salary.view', 'payslip.view')) {
            $tabs[] = ['key' => 'payroll', 'label' => 'Payroll', 'icon' => 'wallet'];
        }
        if ($viewer->hasPermission('document.view')) {
            $tabs[] = ['key' => 'documents', 'label' => 'Documents', 'icon' => 'folder'];
        }
        if ($viewer->hasPermission('activity.view')) {
            $tabs[] = ['key' => 'activity', 'label' => 'Activity', 'icon' => 'history'];
        }

        return $tabs;
    }

    public function canSeeAttendance(User $viewer, Employee $employee): bool
    {
        return $this->scope->canAccess($viewer, $employee, 'attendance.view', 'attendance.view_team', false);
    }

    /** Assignment effective today, otherwise the latest one (terminated employees keep their last placement on show). */
    public function placement(Employee $employee): ?EmployeeAssignment
    {
        $today = $this->context->today()->toDateString();
        $all = $this->history($employee);

        return $all->first(fn ($a) => $a->effective_from->toDateString() <= $today
            && ($a->effective_to === null || $a->effective_to->toDateString() >= $today)) ?? $all->first();
    }

    /** @return Collection<int, EmployeeAssignment> newest first */
    public function history(Employee $employee): Collection
    {
        return $employee->assignments()->reorder()
            ->with(['branch', 'department', 'position', 'manager:id,full_name,employee_number', 'workLocation', 'shift'])
            ->orderByDesc('effective_from')->orderByDesc('id')
            ->get();
    }

    /**
     * @return array{records: Collection<int, Attendance>, summary: array<string, int|float>}
     */
    public function attendance(Employee $employee): array
    {
        $records = Attendance::query()
            ->where('employee_id', $employee->id)
            ->with('schedule:id,shift_name,location_name')
            ->orderByDesc('work_date')->orderByDesc('id')
            ->limit(30)->get();

        $worked = $records->whereNotNull('check_in_at');

        return [
            'records' => $records,
            'summary' => [
                'days' => $worked->count(),
                'late' => $worked->where('is_late', true)->count(),
                'hours' => round($worked->sum('worked_minutes') / 60, 1),
                'flagged' => $records->where('needs_review', true)->count(),
            ],
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function activity(Employee $employee): Collection
    {
        return $this->presenter->present(
            Activity::query()->with('actor')
                ->where('entity_type', $employee->getMorphClass())->where('entity_id', $employee->id)
                ->latest('id')->limit(50)->get(),
        );
    }
}
