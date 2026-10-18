<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/** Create (and by default submit) or preview a leave request. Business rules live in LeaveService. */
class LeaveRequestStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('leave.request');
    }

    public function rules(): array
    {
        $updating = $this->isMethod('PATCH');
        $required = $updating ? 'sometimes' : 'required';

        return [
            'leave_type_id' => [$required, 'integer'],
            'start_date' => [$required, 'date_format:Y-m-d'],
            'end_date' => [$required, 'date_format:Y-m-d'],
            'half_day' => ['nullable', 'in:AM,PM'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'submit' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, mixed> only the fields the service understands */
    public function leaveData(): array
    {
        return $this->safe()->only(['leave_type_id', 'start_date', 'end_date', 'half_day', 'reason']);
    }
}
