<?php

namespace App\Http\Requests\Admin\Concerns;

use App\Enums\EmployeeStatus;
use App\Support\CompanyContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/** Shared rules for per-employee date-ranged overrides. Ranges start today or later: past days keep their schedule. */
trait EmployeeRangeRules
{
    protected function liveEmployee(): Exists
    {
        return Rule::exists('employees', 'id')
            ->where('company_id', $this->companyId())
            ->where(fn ($q) => $q->where('status', '!=', EmployeeStatus::Terminated->value))
            ->whereNull('deleted_at');
    }

    protected function today(): string
    {
        return app(CompanyContext::class)->today()->toDateString();
    }
}
