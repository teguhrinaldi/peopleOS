<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

/** HR filing leave on behalf of an employee. Business rules (balance, notice, overlap) are the LeaveService's. */
class FileLeaveRequest extends AdminRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('leave.manage');
    }

    protected function prepareForValidation(): void
    {
        $this->normalize(['leave_type_id', 'start_date', 'end_date', 'half_day', 'reason']);
        // A half-day is a single date.
        if ($this->input('half_day') && $this->input('start_date') && ! $this->input('end_date')) {
            $this->merge(['end_date' => $this->input('start_date')]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'leave_type_id' => ['required', 'integer', $this->existsInCompany('leave_types')->where('is_active', true)],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'half_day' => ['nullable', Rule::in(['AM', 'PM'])],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return parent::attributes() + ['leave_type_id' => 'leave type', 'start_date' => 'start date', 'end_date' => 'end date', 'half_day' => 'half day'];
    }
}
