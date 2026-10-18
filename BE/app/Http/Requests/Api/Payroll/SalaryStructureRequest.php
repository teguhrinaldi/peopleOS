<?php

namespace App\Http\Requests\Api\Payroll;

use Illuminate\Foundation\Http\FormRequest;

class SalaryStructureRequest extends FormRequest
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
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'components' => [$required, 'array', 'max:50'],
            'components.*.salary_component_id' => ['required', 'integer', 'distinct'],
            'components.*.amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'components.*.rate' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'components.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:65000'],
        ];
    }

    /** @return array<string, mixed> */
    public function fields(): array
    {
        return $this->safe()->only(['code', 'name', 'description', 'is_active']);
    }

    /** @return list<array<string, mixed>>|null */
    public function components(): ?array
    {
        return $this->has('components') ? array_values($this->validated('components')) : null;
    }
}
