<?php

namespace App\Http\Requests\Admin;

/** Assign a structure to employees from a date. Employee ids are re-resolved inside the company by the action. */
class SalaryAssignRequest extends AdminRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('salary.manage');
    }

    protected function prepareForValidation(): void
    {
        $this->normalize(['effective_from', 'reason']);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employee_ids' => ['required', 'array', 'min:1', 'max:500'],
            'employee_ids.*' => ['integer', $this->existsInCompany('employees')],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return ['employee_ids.required' => 'Choose at least one employee.', 'employee_ids.min' => 'Choose at least one employee.'];
    }
}
