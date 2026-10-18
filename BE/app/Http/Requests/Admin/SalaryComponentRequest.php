<?php

namespace App\Http\Requests\Admin;

use App\Enums\SalaryCalculation;
use App\Enums\SalaryComponentKind;
use Illuminate\Validation\Rule;

/** A salary component (earning or deduction). Semantic rules (basic, in-use locks) are enforced by the service. */
class SalaryComponentRequest extends AdminRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('salary.manage');
    }

    protected function prepareForValidation(): void
    {
        $this->normalize(['code', 'name', 'kind', 'calculation', 'default_amount', 'default_rate', 'description', 'sort_order'], upper: ['code', 'kind', 'calculation']);
        $this->merge(['is_basic' => $this->boolean('is_basic'), 'is_active' => $this->boolean('is_active', true)]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9._-]+$/'],
            'name' => ['required', 'string', 'max:100'],
            'kind' => ['required', Rule::enum(SalaryComponentKind::class)],
            'calculation' => ['required', Rule::enum(SalaryCalculation::class)],
            'is_basic' => ['boolean'],
            'is_active' => ['boolean'],
            'default_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'default_rate' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'description' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'between:0,9999'],
        ];
    }

    public function attributes(): array
    {
        return parent::attributes() + ['default_amount' => 'default amount', 'default_rate' => 'default percentage', 'is_basic' => 'basic flag', 'sort_order' => 'order'];
    }

    /** @return array<string, mixed> */
    public function componentData(): array
    {
        return $this->validated() + ['sort_order' => 0];
    }
}
