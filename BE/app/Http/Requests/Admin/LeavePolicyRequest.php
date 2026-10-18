<?php

namespace App\Http\Requests\Admin;

use App\Enums\EmploymentType;
use App\Http\Requests\Admin\Concerns\LeavePolicyRules;
use Illuminate\Validation\Rule;

/**
 * A policy for one employment type of a leave type (overrides the default for those employees).
 * Fields are posted under `ep[...]` so they never collide with the default-policy form on the same page.
 */
class LeavePolicyRequest extends AdminRequest
{
    use LeavePolicyRules;

    public function authorize(): bool
    {
        return $this->user()->hasPermission('leave.manage');
    }

    protected function prepareForValidation(): void
    {
        $this->normalizePolicy('ep');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['employment_type' => ['required', Rule::enum(EmploymentType::class)], 'ep' => ['required', 'array'], ...$this->policyRules('ep.')];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->policyMessages('ep.');
    }

    /** @return array<string, mixed> */
    public function policyData(): array
    {
        return ['employment_type' => $this->validated('employment_type')]
            + array_intersect_key($this->validated('ep'), array_flip($this->policyFields()));
    }
}
