<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\AssignmentRules;
use App\Models\Employee;
use App\Services\Admin\RoleAssignment;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreEmployeeRequest extends EmployeeRequest
{
    use AssignmentRules;

    public function authorize(): bool
    {
        return $this->user()->can('create', Employee::class);
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $this->normalize([...$this->assignmentFields(), 'effective_from']);
        $this->merge(['create_login' => $this->boolean('create_login')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $login = $this->boolean('create_login');

        return [
            ...$this->profileRules(),
            ...$this->statusRule(),
            ...$this->assignmentRules(),
            'email' => ['nullable', Rule::requiredIf($login), 'email:rfc', 'max:255',
                $this->uniqueInCompany('employees', 'email'),
                ...($login ? [Rule::unique('users', 'email')] : [])],
            'effective_from' => ['nullable', 'date', 'after_or_equal:join_date'],
            'create_login' => ['boolean'],
            'role_slug' => [Rule::requiredIf($login), 'nullable', Rule::in(array_keys(app(RoleAssignment::class)->assignableTo($this->user())))],
            'password' => [Rule::requiredIf($login), 'nullable', 'string', Password::min(10)->letters()->numbers()],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return parent::messages() + [
            'role_slug.in' => 'You cannot assign that role.',
            'email.required' => 'An email address is required to create a login.',
        ];
    }

    /** @return array<string, mixed> validated fields without the uploaded file */
    public function employeeData(): array
    {
        $data = $this->safe()->except('photo');
        if (! $data['create_login']) {
            unset($data['password'], $data['role_slug']);
        }

        return $data;
    }
}
