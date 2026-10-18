<?php

namespace App\Http\Requests\Admin\Concerns;

use App\Enums\EmployeeStatus;
use Illuminate\Validation\Rule;

/** Placement fields (used by create and by "change assignment"). Needs the AdminRequest helpers. */
trait AssignmentRules
{
    /** @return array<string, mixed> */
    protected function assignmentRules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', $this->existsInCompany('branches')],
            'department_id' => ['required', 'integer', $this->existsInCompany('departments')],
            'position_id' => ['required', 'integer', $this->existsInCompany('positions')],
            'manager_id' => ['nullable', 'integer', Rule::exists('employees', 'id')
                ->where('company_id', $this->companyId())
                ->where('status', EmployeeStatus::Active->value)
                ->whereNull('deleted_at')],
            'work_location_id' => ['nullable', 'integer', $this->existsInCompany('work_locations')],
            'shift_id' => ['nullable', 'integer', $this->existsInCompany('shifts')],
            'assignment_reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return list<string> */
    protected function assignmentFields(): array
    {
        return ['branch_id', 'department_id', 'position_id', 'manager_id', 'work_location_id', 'shift_id', 'assignment_reason'];
    }
}
