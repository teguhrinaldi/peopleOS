<?php

namespace App\Http\Requests\Admin;

use App\Enums\LeaveStatus;
use Illuminate\Validation\Rule;

class LeaveIndexRequest extends AdminRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasAnyPermission('leave.view', 'leave.view_team', 'leave.approve', 'leave.approve_team');
    }

    protected function prepareForValidation(): void
    {
        $this->normalize(['status', 'leave_type_id', 'department_id', 'employee_id', 'from', 'to', 'month']);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $statuses = array_values(array_diff(array_column(LeaveStatus::cases(), 'value'), [LeaveStatus::Draft->value]));

        return [
            'status' => ['nullable', Rule::in($statuses)],
            'leave_type_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'employee_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'month' => ['nullable', 'date_format:Y-m'],
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return $this->safe()->only(['status', 'leave_type_id', 'department_id', 'employee_id', 'from', 'to']);
    }
}
