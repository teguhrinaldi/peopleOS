<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\LeavePolicyRules;
use App\Models\LeaveType;
use App\Support\LeaveColors;
use Illuminate\Validation\Rule;

/** Leave type + its default policy (the policy used when no employment-type policy matches). */
class LeaveTypeRequest extends AdminRequest
{
    use LeavePolicyRules;

    public function authorize(): bool
    {
        return $this->user()->hasPermission('leave.manage');
    }

    protected function prepareForValidation(): void
    {
        $this->normalize(['code', 'name', 'description', 'color', 'sort_order'], upper: ['code']);
        $this->merge(['is_paid' => $this->boolean('is_paid'), 'is_active' => $this->boolean('is_active')]);
        $this->normalizePolicy('policy');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var LeaveType|null $current */
        $current = $this->route('leaveType');

        return [
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9._-]+$/', $this->uniqueInCompany('leave_types', 'code', $current?->id)],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_paid' => ['boolean'],
            'is_active' => ['boolean'],
            'color' => ['required', Rule::in(array_keys(LeaveColors::TOKENS))],
            'sort_order' => ['nullable', 'integer', 'between:0,999'],
            'policy' => ['required', 'array'],
            ...$this->policyRules('policy.'),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->policyMessages('policy.') + [
            'code.unique' => 'This code is already used by another leave type.',
            'code.regex' => 'Use capital letters, digits, dots, dashes or underscores only.',
        ];
    }

    /** @return array<string, mixed> */
    public function typeData(): array
    {
        $data = $this->safe()->only(['code', 'name', 'description', 'is_paid', 'is_active', 'color', 'sort_order']);
        $data['sort_order'] ??= 0;

        return $data;
    }

    /** @return array<string, mixed> */
    public function policyData(): array
    {
        return array_intersect_key($this->validated('policy'), array_flip($this->policyFields()));
    }
}
