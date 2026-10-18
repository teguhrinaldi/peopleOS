<?php

namespace App\Http\Requests\Admin;

use App\Services\Admin\AttendanceBoard;
use Illuminate\Validation\Rule;

/** Board and CSV filters. Ids are only narrowing: the result is always intersected with AccessScope. */
class AttendanceBoardRequest extends AdminRequest
{
    private const MAX_RANGE_DAYS = 366;

    public function authorize(): bool
    {
        return $this->user()->hasAnyPermission('attendance.view', 'attendance.view_team');
    }

    protected function prepareForValidation(): void
    {
        $this->normalize(['from', 'to', 'department_id', 'work_location_id', 'employee_id', 'status']);
        $this->merge(['review' => $this->boolean('review')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'department_id' => ['nullable', 'integer'],
            'work_location_id' => ['nullable', 'integer'],
            'employee_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(array_keys(AttendanceBoard::statusOptions()))],
            'review' => ['boolean'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            $from = $this->input('from');
            $to = $this->input('to');
            if ($from && $to && strtotime($to) - strtotime($from) > self::MAX_RANGE_DAYS * 86400) {
                $validator->errors()->add('to', 'Choose a range of one year or less.');
            }
        }];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        $f = $this->safe()->only(['from', 'to', 'department_id', 'work_location_id', 'employee_id', 'status', 'review']);
        // A single date entered in "from" means that one day.
        if (! empty($f['from']) && empty($f['to'])) {
            $f['to'] = $f['from'];
        }

        return $f;
    }
}
