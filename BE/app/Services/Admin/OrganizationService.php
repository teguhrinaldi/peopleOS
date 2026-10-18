<?php

namespace App\Services\Admin;

use App\Enums\EmployeeStatus;
use App\Exceptions\ApiException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Position;
use App\Models\User;
use App\Models\WorkLocation;
use App\Services\ActivityLogger;
use App\Services\Admin\Concerns\AuditsChanges;
use App\Support\CompanyContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Departments, positions, branches and the company profile for the web admin.
 * Deleting is soft and refused while people are still placed there (deactivate instead).
 */
class OrganizationService
{
    use AuditsChanges;

    public function __construct(
        private readonly CompanyContext $context,
        private readonly ActivityLogger $activity,
    ) {}

    /** Employees (not terminated) whose current assignment points at each id of the given assignment column. @return array<int, int> */
    public function headcountBy(string $column): array
    {
        abort_unless(in_array($column, ['department_id', 'position_id', 'branch_id', 'work_location_id'], true), 500);
        $today = $this->context->today()->toDateString();

        return DB::table('employee_assignments as ea')
            ->join('employees as e', 'e.id', '=', 'ea.employee_id')
            ->whereNull('e.deleted_at')
            ->where('e.company_id', $this->context->id())
            ->where('e.status', '!=', EmployeeStatus::Terminated->value)
            ->where('ea.effective_from', '<=', $today)
            ->where(fn ($w) => $w->whereNull('ea.effective_to')->orWhere('ea.effective_to', '>=', $today))
            ->whereNotNull("ea.{$column}")
            ->groupBy("ea.{$column}")
            ->selectRaw("ea.{$column} as k, count(distinct ea.employee_id) as n")
            ->pluck('n', 'k')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    // ---- Departments ----------------------------------------------------------------------------

    /** @param array<string, mixed> $data */
    public function saveDepartment(?Department $department, array $data, User $actor): Department
    {
        return DB::transaction(function () use ($department, $data, $actor) {
            $department ??= new Department(['company_id' => $this->context->id()]);
            $this->assertNoDepartmentCycle($department, $data['parent_id'] ?? null);

            return $this->persist($department, $data, ['code', 'name', 'description', 'branch_id', 'parent_id', 'is_active'], 'department', $actor);
        });
    }

    public function deleteDepartment(Department $department, User $actor): void
    {
        $people = $this->headcountBy('department_id')[$department->id] ?? 0;
        if ($people > 0) {
            throw ApiException::make('DEPARTMENT_IN_USE', "{$department->name} still has {$people} ".($people === 1 ? 'employee' : 'employees').'. Reassign them first, or deactivate the department instead.', 409);
        }
        if (Department::query()->where('parent_id', $department->id)->exists()) {
            throw ApiException::make('DEPARTMENT_HAS_CHILDREN', "{$department->name} has sub-departments. Move or remove them first, or deactivate this department instead.", 409);
        }

        $this->remove($department, 'department', $actor);
    }

    // ---- Positions ------------------------------------------------------------------------------

    /** @param array<string, mixed> $data */
    public function savePosition(?Position $position, array $data, User $actor): Position
    {
        return DB::transaction(function () use ($position, $data, $actor) {
            $position ??= new Position(['company_id' => $this->context->id()]);

            return $this->persist($position, $data, ['code', 'name', 'level', 'description', 'is_active'], 'position', $actor);
        });
    }

    public function deletePosition(Position $position, User $actor): void
    {
        $people = $this->headcountBy('position_id')[$position->id] ?? 0;
        if ($people > 0) {
            throw ApiException::make('POSITION_IN_USE', "{$position->name} is still held by {$people} ".($people === 1 ? 'employee' : 'employees').'. Reassign them first, or deactivate the position instead.', 409);
        }

        $this->remove($position, 'position', $actor);
    }

    // ---- Branches -------------------------------------------------------------------------------

    /** @param array<string, mixed> $data */
    public function saveBranch(?Branch $branch, array $data, User $actor): Branch
    {
        return DB::transaction(function () use ($branch, $data, $actor) {
            $branch ??= new Branch(['company_id' => $this->context->id()]);

            return $this->persist($branch, $data, ['code', 'name', 'city', 'address', 'phone', 'is_active'], 'branch', $actor);
        });
    }

    public function deleteBranch(Branch $branch, User $actor): void
    {
        $people = $this->headcountBy('branch_id')[$branch->id] ?? 0;
        if ($people > 0) {
            throw ApiException::make('BRANCH_IN_USE', "{$branch->name} still has {$people} ".($people === 1 ? 'employee' : 'employees').'. Reassign them first, or deactivate the branch instead.', 409);
        }
        if (Department::query()->where('branch_id', $branch->id)->exists() || WorkLocation::query()->where('branch_id', $branch->id)->exists()) {
            throw ApiException::make('BRANCH_IN_USE', "{$branch->name} still has departments or work locations. Move them first, or deactivate the branch instead.", 409);
        }

        $this->remove($branch, 'branch', $actor);
    }

    // ---- Company --------------------------------------------------------------------------------

    /** @param array<string, mixed> $data */
    public function updateCompany(array $data, User $actor): Company
    {
        $company = $this->context->company();
        $company->fill(array_intersect_key($data, array_flip(['name', 'legal_name', 'email', 'phone', 'address', 'timezone', 'logo_path'])));

        if ($company->isDirty()) {
            [$old, $new] = $this->activity->diff($company);
            $company->save();
            $this->activity->log('company.updated', $company, $old, $new, actor: $actor);
            $this->context->forget(); // timezone may have changed
        }

        return $company;
    }

    // ---- Overview -------------------------------------------------------------------------------

    /**
     * Branches -> top-level departments -> sub-departments with headcounts.
     *
     * @return array{branches: Collection<int, array<string, mixed>>, unplaced: Collection<int, array<string, mixed>>, totals: array<string, int>}
     */
    public function overview(): array
    {
        $byDepartment = $this->headcountBy('department_id');
        $byBranch = $this->headcountBy('branch_id');
        $departments = Department::query()->orderBy('name')->get();
        $children = $departments->groupBy('parent_id');

        $node = function (Department $d) use (&$node, $children, $byDepartment): array {
            return [
                'id' => $d->id,
                'name' => $d->name,
                'code' => $d->code,
                'active' => $d->is_active,
                'headcount' => $byDepartment[$d->id] ?? 0,
                'children' => ($children[$d->id] ?? collect())->map($node)->values()->all(),
            ];
        };

        $roots = $departments->filter(fn (Department $d) => $d->parent_id === null || ! $departments->contains('id', $d->parent_id));

        $branches = Branch::query()->orderBy('name')->get()->map(fn (Branch $b) => [
            'id' => $b->id,
            'name' => $b->name,
            'code' => $b->code,
            'city' => $b->city,
            'active' => $b->is_active,
            'headcount' => $byBranch[$b->id] ?? 0,
            'departments' => $roots->where('branch_id', $b->id)->map($node)->values()->all(),
        ]);

        return [
            'branches' => $branches,
            'unplaced' => $roots->whereNull('branch_id')->map($node)->values(),
            'totals' => [
                'branches' => $branches->count(),
                'departments' => $departments->count(),
                'positions' => Position::query()->count(),
                'people' => array_sum($byDepartment),
            ],
        ];
    }

    // ---- internals ------------------------------------------------------------------------------

    private function assertNoDepartmentCycle(Department $department, mixed $parentId): void
    {
        if (! $parentId || ! $department->exists) {
            return;
        }

        $cursor = (int) $parentId;
        $seen = [];
        while ($cursor && ! isset($seen[$cursor])) {
            if ($cursor === $department->id) {
                throw ValidationException::withMessages(['parent_id' => 'A department cannot sit under itself or one of its sub-departments.']);
            }
            $seen[$cursor] = true;
            $cursor = (int) Department::query()->whereKey($cursor)->value('parent_id');
        }
    }
}
