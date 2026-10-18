<?php

namespace App\Http\Requests\Admin;

use App\Models\Position;

class PositionRequest extends AdminRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('organization.manage');
    }

    protected function prepareForValidation(): void
    {
        $this->normalize(['code', 'name', 'description', 'level'], upper: ['code']);
        $this->merge(['is_active' => $this->boolean('is_active')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Position|null $current */
        $current = $this->route('position');

        return [
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9._-]+$/', $this->uniqueInCompany('positions', 'code', $current?->id)],
            'name' => ['required', 'string', 'max:255'],
            'level' => ['nullable', 'integer', 'between:1,20'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.unique' => 'This code is already used by another position.',
            'code.regex' => 'Use capital letters, digits, dots, dashes or underscores only.',
        ];
    }
}
