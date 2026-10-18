<?php

namespace App\Http\Requests\Api\Payroll;

use Illuminate\Foundation\Http\FormRequest;

class AssignStructureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('salary.manage');
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:255'],
            'overrides' => ['sometimes', 'array', 'max:50'],
            'overrides.*.salary_component_id' => ['required', 'integer', 'distinct'],
            'overrides.*.amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'overrides.*.rate' => ['nullable', 'numeric', 'min:0', 'max:1000'],
        ];
    }

    /** @return array<int, array{amount?: mixed, rate?: mixed}> */
    public function overrides(): array
    {
        $out = [];
        foreach ($this->validated('overrides', []) as $o) {
            $out[(int) $o['salary_component_id']] = array_intersect_key($o, array_flip(['amount', 'rate']));
        }

        return $out;
    }
}
