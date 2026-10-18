<?php

namespace App\Http\Requests\Admin;

use App\Support\CompanyContext;

class TerminateEmployeeRequest extends AdminRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('employee'));
    }

    protected function prepareForValidation(): void
    {
        $this->normalize(['terminated_at', 'reason']);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $today = app(CompanyContext::class)->today()->toDateString();

        return [
            'terminated_at' => ['required', 'date', 'before_or_equal:'.$today, 'after_or_equal:'.$this->route('employee')->join_date->toDateString()],
            'reason' => ['required', 'string', 'max:255'],
            'confirm' => ['accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'terminated_at.before_or_equal' => 'The last working day cannot be in the future.',
            'terminated_at.after_or_equal' => 'The last working day cannot be before the join date.',
            'confirm.accepted' => 'Please confirm that you understand this cannot be undone.',
        ];
    }
}
