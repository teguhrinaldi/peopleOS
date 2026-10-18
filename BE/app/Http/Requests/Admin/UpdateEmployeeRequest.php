<?php

namespace App\Http\Requests\Admin;

use App\Models\Employee;

class UpdateEmployeeRequest extends EmployeeRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('employee'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Employee $employee */
        $employee = $this->route('employee');

        return [
            ...$this->profileRules($employee->id),
            'remove_photo' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function employeeData(): array
    {
        return $this->safe()->except(['photo', 'remove_photo']);
    }
}
