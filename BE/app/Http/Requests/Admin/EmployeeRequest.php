<?php

namespace App\Http\Requests\Admin;

use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use Illuminate\Validation\Rule;

/** Personal + employment fields shared by "create" and "edit". Placement fields live in AssignmentRules. */
abstract class EmployeeRequest extends AdminRequest
{
    protected function prepareForValidation(): void
    {
        $this->normalize(
            ['employee_number', 'full_name', 'gender', 'date_of_birth', 'phone', 'email', 'address', 'join_date', 'employment_type'],
            upper: ['employee_number'],
            lower: ['email'],
        );
    }

    /** @return array<string, mixed> */
    protected function profileRules(?int $ignoreEmployeeId = null): array
    {
        return [
            'employee_number' => ['required', 'string', 'max:40', $this->uniqueInCompany('employees', 'employee_number', $ignoreEmployeeId)],
            'full_name' => ['required', 'string', 'max:255'],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\-.\s]{5,40}$/'],
            'email' => ['nullable', 'email:rfc', 'max:255', $this->uniqueInCompany('employees', 'email', $ignoreEmployeeId)],
            'address' => ['nullable', 'string', 'max:1000'],
            'photo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'join_date' => ['required', 'date'],
            'employment_type' => ['required', Rule::enum(EmploymentType::class)],
        ];
    }

    /** @return array<string, mixed> */
    protected function statusRule(): array
    {
        return ['status' => ['required', Rule::in([EmployeeStatus::Active->value, EmployeeStatus::Pending->value])]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'employee_number.unique' => 'This employee number is already in use.',
            'email.unique' => 'This email address is already in use.',
            'photo.max' => 'The photo must be 2 MB or smaller.',
            'photo.image' => 'The photo must be an image (JPG, PNG or WebP).',
            'photo.mimes' => 'The photo must be a JPG, PNG or WebP image.',
            'phone.regex' => 'Use digits, spaces and + ( ) - only.',
        ];
    }
}
