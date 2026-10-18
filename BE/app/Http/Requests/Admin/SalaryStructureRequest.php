<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

/** A salary structure template and its component lines. */
class SalaryStructureRequest extends AdminRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('salary.manage');
    }

    protected function prepareForValidation(): void
    {
        $this->normalize(['code', 'name', 'description'], upper: ['code']);
        $this->merge(['is_active' => $this->boolean('is_active', true)]);
        $lines = collect((array) $this->input('components', []))
            ->filter(fn ($l) => is_array($l) && ($l['salary_component_id'] ?? '') !== '')
            ->map(fn ($l) => array_map(fn ($v) => is_string($v) && trim($v) === '' ? null : $v, $l))->values()->all();
        $this->merge(['components' => $lines]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9._-]+$/'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'components' => ['array', 'max:60'],
            'components.*.salary_component_id' => ['required', 'integer', Rule::exists('salary_components', 'id')->where('company_id', $this->companyId())],
            'components.*.amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'components.*.rate' => ['nullable', 'numeric', 'min:0', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return parent::attributes() + ['components.*.salary_component_id' => 'component', 'components.*.amount' => 'amount', 'components.*.rate' => 'percentage'];
    }
}
