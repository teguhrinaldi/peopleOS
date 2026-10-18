<?php

namespace App\Http\Requests\Admin;

use App\Models\SalaryComponent;

/**
 * "Change salary from date": one row per salary component with the new value (blank = leave as is / not held)
 * and an optional remove flag. Turned into the service's changes list; the service refuses overlapping dates.
 */
class EmployeeSalaryChangeRequest extends AdminRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('salary.manage');
    }

    protected function prepareForValidation(): void
    {
        $this->normalize(['effective_from', 'reason']);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:255'],
            'values' => ['nullable', 'array', 'max:80'],
            'values.*' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'remove' => ['nullable', 'array', 'max:80'],
            'remove.*' => ['integer'],
        ];
    }

    public function attributes(): array
    {
        return parent::attributes() + ['values.*' => 'value'];
    }

    /** @return list<array{salary_component_id: int, amount?: string, rate?: string, remove?: bool}> */
    public function changes(): array
    {
        $components = SalaryComponent::query()->where('company_id', $this->companyId())->get()->keyBy('id');
        $changes = [];
        foreach ((array) $this->input('remove', []) as $id) {
            if ($components->has((int) $id)) {
                $changes[(int) $id] = ['salary_component_id' => (int) $id, 'remove' => true];
            }
        }
        foreach ((array) $this->input('values', []) as $id => $value) {
            $component = $components->get((int) $id);
            if (! $component || $value === null || $value === '' || isset($changes[(int) $id])) {
                continue;
            }
            $changes[(int) $id] = ['salary_component_id' => (int) $id] + ($component->calculation->usesRate() ? ['rate' => (string) $value] : ['amount' => (string) $value]);
        }

        return array_values($changes);
    }
}
