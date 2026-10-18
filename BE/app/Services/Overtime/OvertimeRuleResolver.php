<?php

namespace App\Services\Overtime;

use App\Models\Employee;
use App\Models\OvertimeRule;
use Illuminate\Support\Collection;

/**
 * Which overtime rule applies to an employee on a work date: active, effective on that date, scope matching
 * (employment_type / department, NULL = any). Most specific wins (both scopes > one > none), then higher
 * priority, then the later effective_from, then the higher id.
 */
class OvertimeRuleResolver
{
    /** @var array<int, Collection<int, OvertimeRule>> */
    private array $cache = [];

    public function resolve(Employee $employee, string $date): ?OvertimeRule
    {
        $rules = $this->cache[$employee->company_id] ??= OvertimeRule::query()
            ->where('company_id', $employee->company_id)
            ->where('is_active', true)
            ->get();

        $departmentId = $employee->assignmentOn($date)?->department_id;
        $type = $employee->employment_type?->value;

        return $rules
            ->filter(fn (OvertimeRule $r) => $r->effective_from->toDateString() <= $date
                && ($r->effective_to === null || $r->effective_to->toDateString() >= $date)
                && ($r->employment_type === null || $r->employment_type === $type)
                && ($r->department_id === null || $r->department_id === $departmentId))
            ->sortByDesc(fn (OvertimeRule $r) => sprintf(
                '%d-%05d-%s-%010d',
                ($r->employment_type !== null) + ($r->department_id !== null),
                $r->priority,
                $r->effective_from->toDateString(),
                $r->id,
            ))
            ->first();
    }

    public function forget(): void
    {
        $this->cache = [];
    }
}
