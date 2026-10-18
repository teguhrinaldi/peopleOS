<?php

namespace App\Http\Requests\Api\Payroll;

use Illuminate\Foundation\Http\FormRequest;

class PayrollPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('payroll.manage');
    }

    public function rules(): array
    {
        $create = $this->isMethod('post');

        return [
            'start_date' => [$create ? 'required' : 'prohibited', 'date_format:Y-m-d'],
            'end_date' => [$create ? 'required' : 'prohibited', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'pay_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
