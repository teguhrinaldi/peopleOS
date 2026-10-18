<?php

namespace App\Services\Admin;

use App\Enums\AttendanceEventType;
use App\Enums\AttendanceStatus;
use App\Enums\EmployeeStatus;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\User;
use App\Services\AccessScope;
use App\Support\CompanyContext;
use App\Support\Fmt;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * The attendance board: records the viewer may see (AccessScope: all or direct reports), filtered by
 * local work date and placement *as it was on that date*. Read-only; decisions go through the domain services.
 *
 * Filters: from, to (Y-m-d, company-local work dates), department_id, work_location_id, status
 * (an AttendanceStatus value, or LATE / EARLY), review (bool), employee_id.
 */
class AttendanceBoard
{
    public const EXTRA_STATUSES = ['LATE' => 'Late', 'EARLY' => 'Left early'];

    public function __construct(
        private readonly AccessScope $scope,
        private readonly CompanyContext $context,
        private readonly CompanyCalendar $calendar,
    ) {}

    /**
     * Resolve the date window. No dates: today. "Needs review" without dates: all dates,
     * so the dashboard's flagged count and this list always agree.
     *
     * @param  array<string, mixed>  $f
     * @return array{from: ?string, to: ?string}
     */
    public function window(array $f): array
    {
        $from = $f['from'] ?? null;
        $to = $f['to'] ?? null;

        if ($from === null && $to === null && empty($f['review'])) {
            $from = $to = $this->context->today()->toDateString();
        }

        return ['from' => $from, 'to' => $to];
    }

    /** @param array<string, mixed> $f */
    public function paginate(User $user, array $f, int $perPage = 50): LengthAwarePaginator
    {
        return $this->query($user, $f)
            ->with(['employee:id,full_name,employee_number,photo_path', 'schedule', 'events'])
            ->paginate($perPage)
            ->withQueryString();
    }

    /** Sorted rows with the department the employee belonged to on each work date. @param array<string, mixed> $f */
    public function query(User $user, array $f): Builder
    {
        return $this->filtered($user, $f)
            ->select('attendance.*')
            ->addSelect(['department_name' => $this->departmentOnWorkDate()])
            ->join('employees', 'employees.id', '=', 'attendance.employee_id')
            ->orderByDesc('attendance.work_date')->orderBy('employees.full_name')->orderBy('attendance.id');
    }

    /** @param array<string, mixed> $f */
    private function filtered(User $user, array $f): Builder
    {
        ['from' => $from, 'to' => $to] = $this->window($f);

        $query = Attendance::query()
            ->whereIn('attendance.employee_id', $this->visibleEmployees($user))
            ->when($from, fn (Builder $q) => $q->where('attendance.work_date', '>=', $from))
            ->when($to, fn (Builder $q) => $q->where('attendance.work_date', '<=', $to))
            ->when($f['employee_id'] ?? null, fn (Builder $q, $id) => $q->where('attendance.employee_id', $id))
            ->when(! empty($f['review']), fn (Builder $q) => $q->where('attendance.needs_review', true))
            ->when($f['work_location_id'] ?? null, fn (Builder $q, $id) => $q->whereIn('attendance.attendance_schedule_id',
                fn (QueryBuilder $s) => $s->select('id')->from('attendance_schedules')->where('work_location_id', $id)))
            ->when($f['department_id'] ?? null, fn (Builder $q, $id) => $q->whereExists(
                fn (QueryBuilder $s) => $this->assignmentOnWorkDate($s, 'fd')->where('fd.department_id', $id)));

        match ($f['status'] ?? null) {
            null, '' => null,
            'LATE' => $query->where('attendance.is_late', true),
            'EARLY' => $query->where('attendance.is_early_leave', true),
            default => $query->where('attendance.status', $f['status']),
        };

        return $query;
    }

    /** @param array<string, mixed> $f @return array<string, int> */
    public function summary(User $user, array $f): array
    {
        $row = $this->filtered($user, $f)
            ->toBase()
            ->selectRaw('count(*) as records, coalesce(sum(attendance.is_late), 0) as late, coalesce(sum(attendance.is_early_leave), 0) as early,
                coalesce(sum(attendance.needs_review), 0) as review, coalesce(sum(attendance.worked_minutes), 0) as worked')
            ->first();

        return [
            'records' => (int) $row->records,
            'late' => (int) $row->late,
            'early' => (int) $row->early,
            'review' => (int) $row->review,
            'worked_minutes' => (int) $row->worked,
        ];
    }

    /**
     * Single past-or-present working day only: active people in scope with a shift that day and no
     * check-in at all. Placement filters apply; status filters do not (these people have no status).
     *
     * @param  array<string, mixed>  $f
     * @return Collection<int, Employee>|null null when the view is not a single working day
     */
    public function notCheckedIn(User $user, array $f): ?Collection
    {
        ['from' => $from, 'to' => $to] = $this->window($f);
        if ($from === null || $from !== $to || $from > $this->context->today()->toDateString()
            || ! empty($f['status']) || ! empty($f['review']) || ! $this->calendar->isWorkingDay($from)) {
            return null;
        }

        return Employee::query()
            ->whereIn('employees.id', $this->visibleEmployees($user))
            ->where('employees.status', EmployeeStatus::Active->value)
            ->when($f['employee_id'] ?? null, fn (Builder $q, $id) => $q->whereKey($id))
            ->whereExists(fn (QueryBuilder $s) => $s->selectRaw('1')->from('employee_assignments as na')
                ->whereColumn('na.employee_id', 'employees.id')->whereNotNull('na.shift_id')
                ->where('na.effective_from', '<=', $from)
                ->where(fn ($w) => $w->whereNull('na.effective_to')->orWhere('na.effective_to', '>=', $from))
                ->when($f['department_id'] ?? null, fn ($w, $id) => $w->where('na.department_id', $id))
                ->when($f['work_location_id'] ?? null, fn ($w, $id) => $w->where('na.work_location_id', $id)))
            ->whereNotExists(fn (QueryBuilder $s) => $s->selectRaw('1')->from('attendance as a')
                ->whereColumn('a.employee_id', 'employees.id')->where('a.work_date', $from)->whereNotNull('a.check_in_at'))
            ->orderBy('full_name')
            ->limit(200)
            ->get(['id', 'full_name', 'employee_number', 'photo_path']);
    }

    /**
     * Flat rows for CSV, streamed in chunks so large ranges never sit in memory.
     *
     * @param  array<string, mixed>  $f
     * @return LazyCollection<int, list<string|int|null>>
     */
    public function exportRows(User $user, array $f): LazyCollection
    {
        return $this->query($user, $f)
            ->with(['employee:id,full_name,employee_number', 'schedule:id,shift_name,location_name', 'events'])
            ->lazy(500)
            ->map(function (Attendance $a) {
                $in = $a->eventOfType(AttendanceEventType::CheckIn);

                return [
                    $a->work_date->toDateString(),
                    $a->employee?->employee_number,
                    $a->employee?->full_name,
                    $a->department_name,
                    $a->schedule?->shift_name,
                    $a->schedule?->location_name,
                    Fmt::time($a->check_in_at),
                    Fmt::time($a->check_out_at),
                    $a->worked_minutes,
                    $a->late_minutes,
                    $a->early_leave_minutes,
                    $in?->distance_meters !== null ? (int) round($in->distance_meters) : null,
                    $in?->inside_geofence === null ? null : ($in->inside_geofence ? 'yes' : 'no'),
                    $a->events->contains('is_offline_sync', true) ? 'yes' : 'no',
                    $a->status->value,
                    $a->needs_review ? 'yes' : 'no',
                ];
            });
    }

    /** @return list<string> */
    public static function exportHeader(): array
    {
        return ['work_date', 'employee_number', 'employee', 'department', 'shift', 'location', 'check_in', 'check_out',
            'worked_minutes', 'late_minutes', 'early_leave_minutes', 'check_in_distance_m', 'inside_geofence', 'offline', 'status', 'needs_review'];
    }

    /** @return array<string, string> */
    public static function statusOptions(): array
    {
        $out = [];
        foreach (AttendanceStatus::cases() as $case) {
            $out[$case->value] = ucfirst(strtolower(str_replace('_', ' ', $case->value)));
        }

        return $out + self::EXTRA_STATUSES;
    }

    private function visibleEmployees(User $user): Builder
    {
        return $this->scope->employees($user, 'attendance.view', 'attendance.view_team', false)->select('employees.id');
    }

    private function departmentOnWorkDate(): QueryBuilder
    {
        return $this->assignmentOnWorkDate(DB::table('employee_assignments as da'), 'da', select: false)
            ->join('departments as dd', 'dd.id', '=', 'da.department_id')
            ->select('dd.name')
            ->orderByDesc('da.effective_from')
            ->limit(1);
    }

    /** Assignment rows of the outer attendance row's employee that were effective on its work date. */
    private function assignmentOnWorkDate(QueryBuilder $q, string $alias, bool $select = true): QueryBuilder
    {
        if ($select) {
            $q->selectRaw('1')->from("employee_assignments as {$alias}");
        }

        return $q->whereColumn("{$alias}.employee_id", 'attendance.employee_id')
            ->whereColumn("{$alias}.effective_from", '<=', 'attendance.work_date')
            ->where(fn ($w) => $w->whereNull("{$alias}.effective_to")->orWhereColumn("{$alias}.effective_to", '>=', 'attendance.work_date'));
    }
}
