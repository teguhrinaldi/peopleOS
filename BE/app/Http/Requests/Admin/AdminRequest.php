<?php

namespace App\Http\Requests\Admin;

use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * Shared helpers so every id coming from the browser is checked against this company's live data.
 */
abstract class AdminRequest extends FormRequest
{
    /** @return array<string, string> friendlier field names in validation messages ("department", not "department id") */
    public function attributes(): array
    {
        return [
            'department_id' => 'department', 'position_id' => 'position', 'manager_id' => 'manager', 'branch_id' => 'branch',
            'work_location_id' => 'work location', 'shift_id' => 'shift', 'parent_id' => 'parent department',
            'effective_from' => 'effective date', 'terminated_at' => 'last working day', 'role_slug' => 'role',
            'employee_number' => 'employee number', 'join_date' => 'join date', 'date_of_birth' => 'date of birth',
            'employment_type' => 'employment type', 'assignment_reason' => 'note',
        ];
    }

    protected function companyId(): int
    {
        return app(CompanyContext::class)->id();
    }

    /** The id must exist, belong to this company and not be soft-deleted. */
    protected function existsInCompany(string $table): Exists
    {
        return Rule::exists($table, 'id')->where('company_id', $this->companyId())->whereNull('deleted_at');
    }

    /** Unique per company. Soft-deleted rows count too, because the database index does. */
    protected function uniqueInCompany(string $table, string $column, ?int $ignoreId = null): Unique
    {
        return Rule::unique($table, $column)->where('company_id', $this->companyId())->ignore($ignoreId);
    }

    /** Trim strings and turn empty strings into null so optional fields validate as absent. */
    protected function normalize(array $fields, array $upper = [], array $lower = []): void
    {
        $clean = [];
        foreach ($fields as $field) {
            if ($this->has($field)) {
                $clean[$field] = $this->cleanValue($field, $this->input($field), $upper, $lower);
            }
        }
        $this->merge($clean);
    }

    private function cleanValue(string $field, mixed $value, array $upper, array $lower): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (in_array($field, $upper, true)) {
            return mb_strtoupper($value);
        }

        return in_array($field, $lower, true) ? mb_strtolower($value) : $value;
    }
}
