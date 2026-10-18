<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\AssignmentRules;

class ReassignEmployeeRequest extends AdminRequest
{
    use AssignmentRules;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('employee'));
    }

    protected function prepareForValidation(): void
    {
        $this->normalize([...$this->assignmentFields(), 'effective_from']);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->assignmentRules(),
            'effective_from' => ['required', 'date', 'after_or_equal:'.$this->route('employee')->join_date->toDateString()],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['effective_from.after_or_equal' => 'The effective date cannot be before the employee joined.'];
    }
}
