<?php

namespace App\Services\Admin;

use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkLocation;
use App\Support\CompanyContext;
use App\Support\Settings;
use Illuminate\Support\Str;

/** Select options for the employee form and the "change assignment" dialog. Only active reference data is offered. */
class EmployeeFormOptions
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly RoleAssignment $roles,
        private readonly Settings $settings,
    ) {}

    /**
     * Next employee number from Settings (prefix + zero-padded sequence): one past the highest number that
     * already uses the prefix, soft-deleted employees included because the unique index counts them too.
     */
    public function suggestedNumber(): string
    {
        $prefix = $this->settings->string('employee.number_prefix');
        $pattern = '/^'.preg_quote($prefix, '/').'(\d+)$/';

        $highest = Employee::withTrashed()->where('company_id', $this->context->id())
            ->when($prefix !== '', fn ($q) => $q->where('employee_number', 'like', addcslashes($prefix, '\\%_').'%'))
            ->pluck('employee_number')
            ->map(fn ($n) => preg_match($pattern, (string) $n, $m) ? (int) $m[1] : 0)
            ->max() ?? 0;

        $taken = fn (string $n) => Employee::withTrashed()->where('company_id', $this->context->id())->where('employee_number', $n)->exists();
        $sequence = $highest + 1;
        while ($taken($candidate = $this->settings->nextEmployeeNumber($sequence))) {
            $sequence++;
        }

        return $candidate;
    }

    /** @return array<string, array<int|string, string>> */
    public function assignment(?Employee $exceptManager = null): array
    {
        $cid = $this->context->id();

        return [
            'branches' => Branch::query()->where('company_id', $cid)->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
            'departments' => Department::query()->where('company_id', $cid)->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
            'positions' => Position::query()->where('company_id', $cid)->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
            'locations' => WorkLocation::query()->where('company_id', $cid)->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
            'shifts' => Shift::query()->where('company_id', $cid)->where('is_active', true)->orderBy('start_time')->get()
                ->mapWithKeys(fn (Shift $s) => [$s->id => "{$s->name} ({$s->startLabel()}–{$s->endLabel()})"])->all(),
            'managers' => Employee::query()->where('company_id', $cid)->where('status', EmployeeStatus::Active->value)
                ->when($exceptManager, fn ($q) => $q->whereKeyNot($exceptManager->id))
                ->orderBy('full_name')->get(['id', 'full_name', 'employee_number'])
                ->mapWithKeys(fn ($e) => [$e->id => "{$e->full_name} ({$e->employee_number})"])->all(),
        ];
    }

    /** @return array<string, array<int|string, string>> */
    public function form(User $actor, ?Employee $employee = null): array
    {
        return $this->assignment($employee) + [
            'types' => collect(EmploymentType::cases())->mapWithKeys(fn ($t) => [$t->value => Str::headline($t->value)])->all(),
            'genders' => ['male' => 'Male', 'female' => 'Female'],
            'statuses' => [EmployeeStatus::Active->value => 'Active', EmployeeStatus::Pending->value => 'Pending (not started yet)'],
            'roles' => $this->roles->assignableTo($actor),
        ] + ($employee ? [] : ['suggested_number' => $this->suggestedNumber()]);
    }
}
