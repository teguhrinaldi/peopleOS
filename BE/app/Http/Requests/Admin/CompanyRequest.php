<?php

namespace App\Http\Requests\Admin;

class CompanyRequest extends AdminRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('organization.manage');
    }

    protected function prepareForValidation(): void
    {
        $this->normalize(['name', 'legal_name', 'email', 'phone', 'address', 'timezone'], lower: ['email']);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\-.\s]{5,40}$/'],
            'address' => ['nullable', 'string', 'max:1000'],
            'timezone' => ['required', 'timezone:all'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'timezone' => 'Choose a valid time zone from the list.',
            'phone.regex' => 'Use digits, spaces and + ( ) - only.',
        ];
    }
}
