<?php

namespace App\Http\Requests\Admin;

use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Models\Employee;
use App\Services\Admin\EmployeeDirectory;
use Illuminate\Validation\Rule;

/** Query-string filters of the employee list. Malformed values bounce back with a validation error. */
class EmployeeIndexRequest extends AdminRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Employee::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(EmployeeStatus::class)],
            'employment_type' => ['nullable', Rule::enum(EmploymentType::class)],
            'department_id' => ['nullable', 'integer'],
            'position_id' => ['nullable', 'integer'],
            'work_location_id' => ['nullable', 'integer'],
            'manager_id' => ['nullable', 'integer'],
            'shift_id' => ['nullable', 'integer'],
            'sort' => ['nullable', Rule::in(EmployeeDirectory::SORTS)],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return $this->safe()->only(['q', 'status', 'employment_type', 'department_id', 'position_id', 'work_location_id', 'manager_id', 'shift_id', 'sort', 'dir']);
    }
}
