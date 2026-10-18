<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\EmployeeRangeRules;

class CalendarAssignmentRequest extends AdminRequest
{
    use EmployeeRangeRules;

    public function authorize(): bool
    {
        return $this->user()->hasPermission('attendance.manage');
    }

    protected function prepareForValidation(): void
    {
        $this->normalize(['employee_id', 'working_calendar_id', 'effective_from', 'effective_to']);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', $this->liveEmployee()],
            'working_calendar_id' => ['required', 'integer', $this->existsInCompany('working_calendars')],
            'effective_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$this->today()],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['effective_from.after_or_equal' => 'Assignments start today or later; past days keep the calendar they had.'];
    }
}
