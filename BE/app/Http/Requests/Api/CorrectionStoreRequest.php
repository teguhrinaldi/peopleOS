<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class CorrectionStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('correction.request');
    }

    public function rules(): array
    {
        return [
            'work_date' => ['required', 'date_format:Y-m-d'],
            'requested_check_in_at' => ['nullable', 'date', 'required_without:requested_check_out_at'],
            'requested_check_out_at' => ['nullable', 'date', 'required_without:requested_check_in_at'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
