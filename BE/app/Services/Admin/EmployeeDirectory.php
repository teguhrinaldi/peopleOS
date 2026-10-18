<?php

namespace App\Services\Admin;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Position;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkLocation;
use App\Services\AccessScope;
use App\Support\CompanyContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The employee list: scoped by AccessScope, filtered, sorted and paginated, with each row's
 * placement attached in a single extra query (no N+1).
 */
class EmployeeDirectory
{
    public const SORTS = ['name', 'number', 'department', 'position', 'status', 'join_date'];

    public function __construct(
        private readonly AccessScope $scope,
        private readonly CompanyContext $context,
    ) {}

    /** @param array<string, mixed> $filters q, status, department_id, position_id, work_location_id, employment_type, sort, dir */
    public function paginate(User $user, array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $page = $this->query($user, $filters)->paginate($perPage)->withQueryString();

        $this->attachPlacement($page->getCollection());

        return $page;
    }

    /** @param array<string, mixed> $filters */
    public function query(User $user, array $filters): Builder
    {
        $query = $this->scope->employees($user, 'employee.view', 'employee.view_team', false)->select('employees.*');

        if ($term = trim((string) ($filters['q'] ?? ''))) {
            $like = '%'.addcslashes($term, '\\%_').'%';
            $query->where(fn (Builder $w) => $w->where('employees.full_name', 'like', $like)
                ->orWhere('employees.employee_number', 'like', $like)
                ->orWhere('employees.email', 'like', $like));
        }

        foreach (['status' => 'employees.status', 'employment_type' => 'employees.employment_type'] as $key => $column) {
            if (! empty($filters[$key])) {
                $query->where($column, $filters[$key]);
            }
        }

        $placement = array_filter([
            'department_id' => $filters['department_id'] ?? null,
            'position_id' => $filters['position_id'] ?? null,
            'work_location_id' => $filters['work_location_id'] ?? null,
            'manager_id' => $filters['manager_id'] ?? null,
            'shift_id' => $filters['shift_id'] ?? null,
        ]);
        if ($placement) {
            $query->whereExists(function (QueryBuilder $q) use ($placement) {
                $this->currentAssignments($q, 'f');
                foreach ($placement as $column => $value) {
                    $q->where("f.{$column}", $value);
                }
            });
        }

        $this->applySort($query, $filters['sort'] ?? 'name', ($filters['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc');

        return $query;
    }

    /** @return array<string, array<int|string, string>> */
    public function filterOptions(?User $viewer = null): array
    {
        $cid = $this->context->id();
        $managerIds = EmployeeAssignment::query()->whereNotNull('manager_id')->whereIn('employee_id', Employee::query()->where('company_id', $cid)->select('id'))->distinct()->pluck('manager_id');
        $managers = ($viewer ? $this->scope->employees($viewer, 'employee.view', 'employee.view_team', true) : Employee::query()->where('employees.company_id', $cid))
            ->whereIn('employees.id', $managerIds)->orderBy('employees.full_name')->pluck('employees.full_name', 'employees.id')->all();

        return [
            'departments' => Department::query()->where('company_id', $cid)->orderBy('name')->pluck('name', 'id')->all(),
            'positions' => Position::query()->where('company_id', $cid)->orderBy('name')->pluck('name', 'id')->all(),
            'locations' => WorkLocation::query()->where('company_id', $cid)->orderBy('name')->pluck('name', 'id')->all(),
            'managers' => $managers,
            'shifts' => Shift::query()->where('company_id', $cid)->orderBy('name')->pluck('name', 'id')->all(),
        ];
    }

    /** Assignment effective today; failing that (terminated / future-dated) the most recent one. */
    private function attachPlacement(Collection $employees): void
    {
        if ($employees->isEmpty()) {
            return;
        }

        $today = $this->context->today()->toDateString();
        $rows = EmployeeAssignment::query()
            ->whereIn('employee_id', $employees->pluck('id'))
            ->with(['department', 'position', 'manager:id,full_name', 'workLocation', 'shift'])
            ->orderByDesc('effective_from')->orderByDesc('id')
            ->get()
            ->groupBy('employee_id');

        foreach ($employees as $employee) {
            $mine = $rows->get($employee->id, collect());
            $current = $mine->first(fn ($a) => $a->effective_from->toDateString() <= $today
                && ($a->effective_to === null || $a->effective_to->toDateString() >= $today));
            $employee->setRelation('currentAssignment', $current ?? $mine->first());
        }
    }

    private function applySort(Builder $query, string $sort, string $dir): void
    {
        $sort = in_array($sort, self::SORTS, true) ? $sort : 'name';

        match ($sort) {
            'number' => $query->orderBy('employees.employee_number', $dir),
            'status' => $query->orderBy('employees.status', $dir),
            'join_date' => $query->orderBy('employees.join_date', $dir),
            'department' => $query->orderBy($this->placementName('departments', 'department_id'), $dir),
            'position' => $query->orderBy($this->placementName('positions', 'position_id'), $dir),
            default => $query->orderBy('employees.full_name', $dir),
        };

        $query->orderBy('employees.id');
    }

    private function placementName(string $table, string $column): QueryBuilder
    {
        $sub = DB::table($table)
            ->select("{$table}.name")
            ->join('employee_assignments as s', "s.{$column}", '=', "{$table}.id")
            ->limit(1);
        $sub->whereColumn('s.employee_id', 'employees.id');
        $today = $this->context->today()->toDateString();
        $sub->where('s.effective_from', '<=', $today)
            ->where(fn ($w) => $w->whereNull('s.effective_to')->orWhere('s.effective_to', '>=', $today));

        return $sub;
    }

    /** Constrain a query builder to assignments effective today, joined on the outer employees row. */
    private function currentAssignments(QueryBuilder $q, string $alias): void
    {
        $today = $this->context->today()->toDateString();
        $q->selectRaw('1')->from("employee_assignments as {$alias}")
            ->whereColumn("{$alias}.employee_id", 'employees.id')
            ->where("{$alias}.effective_from", '<=', $today)
            ->where(fn ($w) => $w->whereNull("{$alias}.effective_to")->orWhere("{$alias}.effective_to", '>=', $today));
    }
}
