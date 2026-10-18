<?php

namespace App\Services\Admin;

use App\Models\Employee;
use App\Models\EmployeeSalaryComponent;
use App\Models\SalaryComponent;
use App\Models\SalaryStructure;
use App\Models\SalaryStructureComponent;
use App\Models\User;
use App\Services\AccessScope;
use App\Services\Payroll\EmployeeSalaryService;
use App\Services\Payroll\Money;
use App\Support\CompanyContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Read models for the salary pages (catalogue, structures, one employee's effective-dated history). */
class SalaryDesk
{
    public const KINDS = ['EARNING' => 'Earning', 'DEDUCTION' => 'Deduction'];

    public const CALCULATIONS = [
        'FIXED' => 'Fixed amount per period',
        'PERCENT_OF_BASIC' => 'Percent of basic salary',
        'PERCENT_OF_GROSS' => 'Percent of gross pay (deduction only)',
        'PER_PRESENT_DAY' => 'Amount per present day',
        'PER_OVERTIME_HOUR' => 'Amount per overtime hour',
        'UNPAID_LEAVE_DEDUCTION' => 'Unpaid leave (deduction only)',
    ];

    public function __construct(
        private readonly CompanyContext $context,
        private readonly EmployeeSalaryService $salaries,
        private readonly AccessScope $scope,
    ) {}

    /** @return Collection<int, SalaryComponent> with a `locked` flag: used components keep their kind/calculation. */
    public function components(): Collection
    {
        $components = SalaryComponent::query()->where('company_id', $this->context->id())->orderBy('sort_order')->orderBy('name')->get();
        $usedByEmployees = EmployeeSalaryComponent::query()->whereIn('salary_component_id', $components->pluck('id'))->distinct()->pluck('salary_component_id')->flip();
        $usedByLines = DB::table('payroll_item_lines')->whereIn('salary_component_id', $components->pluck('id'))->distinct()->pluck('salary_component_id')->flip();
        $inStructures = SalaryStructureComponent::query()->whereIn('salary_component_id', $components->pluck('id'))->distinct()->pluck('salary_component_id')->flip();

        return $components->each(function (SalaryComponent $c) use ($usedByEmployees, $usedByLines, $inStructures) {
            $c->setAttribute('locked', $usedByEmployees->has($c->id) || $usedByLines->has($c->id));
            $c->setAttribute('deletable', ! $c->locked && ! $inStructures->has($c->id));
        });
    }

    /** @return Collection<int, SalaryStructure> */
    public function structures(): Collection
    {
        $today = $this->context->today()->toDateString();
        $structures = SalaryStructure::query()->where('company_id', $this->context->id())->withCount('lines')->orderBy('name')->get();
        $assigned = EmployeeSalaryComponent::query()->whereIn('salary_structure_id', $structures->pluck('id'))->effectiveOn($today)
            ->selectRaw('salary_structure_id, count(distinct employee_id) as n')->groupBy('salary_structure_id')->pluck('n', 'salary_structure_id');

        return $structures->each(fn ($s) => $s->setAttribute('assigned_count', (int) ($assigned[$s->id] ?? 0)));
    }

    /** Active employees the viewer may assign a structure to (salary is company-wide data). */
    public function assignableEmployees(User $viewer): array
    {
        return $this->scope->employees($viewer, 'salary.manage', null, false)
            ->where('status', 'ACTIVE')->orderBy('full_name')->get(['id', 'full_name', 'employee_number'])
            ->mapWithKeys(fn ($e) => [$e->id => $e->full_name.' ('.$e->employee_number.')'])->all();
    }

    /** Component metadata the structure form needs to switch between amount and rate inputs. */
    public function componentChoices(bool $activeOnly = true): array
    {
        return SalaryComponent::query()->where('company_id', $this->context->id())
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (SalaryComponent $c) => [
                'id' => $c->id, 'label' => $c->name.' ('.$c->code.')', 'kind' => $c->kind->value,
                'unit' => $c->calculation->usesAmount() ? 'amount' : ($c->calculation->usesRate() ? 'rate' : 'none'),
                'amount' => $c->default_amount, 'rate' => $c->default_rate ? Money::trimRate((string) $c->default_rate) : null,
                'calc' => self::CALCULATIONS[$c->calculation->value] ?? $c->calculation->value,
            ])->values()->all();
    }

    /**
     * The salary tab of one employee: values in force today, and the whole append-only history grouped by effective date.
     *
     * @return array{current: Collection, history: Collection, groups: Collection, today: string}
     */
    public function forEmployee(Employee $employee): array
    {
        $today = $this->context->today()->toDateString();
        $history = $this->salaries->history($employee);

        return [
            'today' => $today,
            'current' => $this->salaries->valuesOn($employee, $today),
            'history' => $history,
            'groups' => $history->groupBy(fn ($r) => $r->effective_from->toDateString())->sortKeysDesc(),
        ];
    }
}
