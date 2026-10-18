<?php

namespace App\Http\Requests\Api\Payroll;

use Illuminate\Foundation\Http\FormRequest;

class EmployeeSalaryChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('salary.manage');
    }

    public function rules(): array
    {
        return [
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'components' => ['required', 'array', 'min:1', 'max:50'],
            'components.*.salary_component_id' => ['required', 'integer', 'distinct'],
            'components.*.amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'components.*.rate' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'components.*.remove' => ['sometimes', 'boolean'],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function changes(): array
    {
        return array_map(fn ($c) => [
            'salary_component_id' => (int) $c['salary_component_id'],
            'amount' => isset($c['amount']) ? (string) $c['amount'] : null,
            'rate' => isset($c['rate']) ? (string) $c['rate'] : null,
            'remove' => (bool) ($c['remove'] ?? false),
        ], array_values($this->validated('components')));
    }
}
