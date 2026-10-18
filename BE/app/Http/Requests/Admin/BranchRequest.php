<?php

namespace App\Http\Requests\Admin;

use App\Models\Branch;

class BranchRequest extends AdminRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('organization.manage');
    }

    protected function prepareForValidation(): void
    {
        $this->normalize(['code', 'name', 'city', 'address', 'phone'], upper: ['code']);
        $this->merge(['is_active' => $this->boolean('is_active')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Branch|null $current */
        $current = $this->route('branch');

        return [
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9._-]+$/', $this->uniqueInCompany('branches', 'code', $current?->id)],
            'name' => ['required', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:1000'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\-.\s]{5,40}$/'],
            'is_active' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.unique' => 'This code is already used by another branch.',
            'code.regex' => 'Use capital letters, digits, dots, dashes or underscores only.',
            'phone.regex' => 'Use digits, spaces and + ( ) - only.',
        ];
    }
}
