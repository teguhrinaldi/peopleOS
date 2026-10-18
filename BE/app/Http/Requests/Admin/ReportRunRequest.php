<?php

namespace App\Http\Requests\Admin;

/** Filters of a report run or export. Which of them a report honours is decided by the report's own declaration. */
class ReportRunRequest extends AdminRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('report.view');
    }

    protected function prepareForValidation(): void
    {
        $this->normalize(['from', 'to', 'department_id', 'employee_id', 'status', 'period_id', 'days', 'leave_type_id']);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'department_id' => ['nullable', 'integer'], 'employee_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'max:30'], 'period_id' => ['nullable', 'integer'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'], 'leave_type_id' => ['nullable', 'integer'],
        ];
    }

    public function attributes(): array
    {
        return parent::attributes() + ['from' => 'start date', 'to' => 'end date', 'days' => 'number of days', 'period_id' => 'payroll period', 'leave_type_id' => 'leave type'];
    }
}
