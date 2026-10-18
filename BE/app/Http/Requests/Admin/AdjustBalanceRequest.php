<?php

namespace App\Http\Requests\Admin;

/** Manual balance correction by HR. Signed half-day steps; a reason is always recorded. */
class AdjustBalanceRequest extends AdminRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('leave.manage');
    }

    protected function prepareForValidation(): void
    {
        $this->normalize(['delta', 'reason']);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'delta' => ['required', 'regex:/^[+-]?\d{1,3}(\.[05])?$/', 'not_in:0,0.0,+0,-0,-0.0,+0.0'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'delta.regex' => 'Enter a number of days in half-day steps, e.g. 2, -1 or 0.5.',
            'delta.not_in' => 'The adjustment cannot be zero.',
            'reason.required' => 'Explain why the balance is adjusted; it is recorded in the audit log.',
        ];
    }

    public function delta(): string
    {
        return ltrim((string) $this->validated('delta'), '+');
    }
}
