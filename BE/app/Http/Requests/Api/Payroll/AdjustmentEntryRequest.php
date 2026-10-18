<?php

namespace App\Http\Requests\Api\Payroll;

use App\Enums\SalaryComponentKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdjustmentEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('payroll.manage');
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer'],
            'kind' => ['required', Rule::enum(SalaryComponentKind::class)],
            'label' => ['required', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
