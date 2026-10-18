<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

/**
 * Approve / reject with an optional note that becomes required on reject.
 * Who may decide is the domain policy's job (checked inside the domain service).
 */
class DecisionRequest extends AdminRequest
{
    public function authorize(): bool
    {
        return true; // route middleware + domain policy
    }

    protected function prepareForValidation(): void
    {
        $this->normalize(['decision', 'note', 'approved_minutes']);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $request = $this->route('overtimeRequest');

        return [
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'note' => ['nullable', 'required_if:decision,reject', 'string', 'max:255'],
            'approved_minutes' => $request
                ? ['nullable', 'integer', 'min:1', 'max:'.$request->requested_minutes]
                : ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'note.required_if' => 'Tell the employee why this is rejected.',
            'approved_minutes.max' => 'Approved minutes cannot exceed the :max minutes requested.',
        ];
    }

    public function approves(): bool
    {
        return $this->validated('decision') === 'approve';
    }
}
