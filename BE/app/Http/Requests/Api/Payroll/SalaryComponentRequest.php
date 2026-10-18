<?php

namespace App\Http\Requests\Api\Payroll;

use App\Enums\SalaryCalculation;
use App\Enums\SalaryComponentKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SalaryComponentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('salary.manage');
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'code' => [$required, 'string', 'max:30', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'name' => [$required, 'string', 'max:120'],
            'kind' => [$required, Rule::enum(SalaryComponentKind::class)],
            'calculation' => [$required, Rule::enum(SalaryCalculation::class)],
            'is_basic' => ['sometimes', 'boolean'],
            'default_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'default_rate' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'description' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function fields(): array
    {
        return $this->safe()->only(['code', 'name', 'kind', 'calculation', 'is_basic', 'default_amount', 'default_rate', 'description', 'sort_order', 'is_active']);
    }
}
