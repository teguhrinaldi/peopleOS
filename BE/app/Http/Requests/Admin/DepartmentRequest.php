<?php

namespace App\Http\Requests\Admin;

use App\Models\Department;

class DepartmentRequest extends AdminRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('organization.manage');
    }

    protected function prepareForValidation(): void
    {
        $this->normalize(['code', 'name', 'description', 'branch_id', 'parent_id'], upper: ['code']);
        $this->merge(['is_active' => $this->boolean('is_active')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Department|null $current */
        $current = $this->route('department');

        return [
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9._-]+$/', $this->uniqueInCompany('departments', 'code', $current?->id)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'branch_id' => ['nullable', 'integer', $this->existsInCompany('branches')],
            'parent_id' => ['nullable', 'integer', $this->existsInCompany('departments'), 'not_in:'.($current?->id ?? 0)],
            'is_active' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.unique' => 'This code is already used by another department.',
            'code.regex' => 'Use capital letters, digits, dots, dashes or underscores only.',
            'parent_id.not_in' => 'A department cannot be its own parent.',
        ];
    }
}
