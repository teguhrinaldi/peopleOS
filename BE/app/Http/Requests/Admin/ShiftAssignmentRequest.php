<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\EmployeeRangeRules;

class ShiftAssignmentRequest extends AdminRequest
{
    use EmployeeRangeRules;

    public function authorize(): bool
    {
        return $this->user()->hasPermission('attendance.manage');
    }

    protected function prepareForValidation(): void
    {
        $this->normalize(['employee_id', 'shift_id', 'start_date', 'end_date', 'note']);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', $this->liveEmployee()],
            'shift_id' => ['required', 'integer', $this->existsInCompany('shifts')->where('is_active', true)],
            'start_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$this->today()],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['start_date.after_or_equal' => 'Overrides start today or later; past days keep the schedule they had.'];
    }
}
