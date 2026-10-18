<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class OvertimeStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('overtime.request');
    }

    public function rules(): array
    {
        return [
            'work_date' => ['required', 'date_format:Y-m-d'],
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after:start_at'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
