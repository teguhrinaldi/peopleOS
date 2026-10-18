<?php

namespace App\Http\Requests\Admin;

use App\Enums\EmployeeStatus;
use Illuminate\Validation\Rule;

class ChangeEmployeeStatusRequest extends AdminRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('employee'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['status' => ['required', Rule::in([EmployeeStatus::Active->value, EmployeeStatus::Inactive->value])]];
    }
}
