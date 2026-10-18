<?php

namespace App\Services\Admin;

use App\Exceptions\ApiException;
use App\Models\Employee;
use App\Models\SalaryComponent;
use App\Models\SalaryStructure;
use App\Models\User;
use App\Services\AccessScope;
use App\Services\Admin\Concerns\TranslatesDomainErrors;
use App\Services\Payroll\EmployeeSalaryService;
use App\Services\Payroll\SalaryComponentService;
use App\Services\Payroll\SalaryStructureService;
use App\Support\CompanyContext;
use Illuminate\Validation\ValidationException;

/**
 * Web entry point for salary setup writes. Every rule (basic component, in-use locks, append-only history,
 * audit) lives in the Payroll services; this class maps their errors to the right form field.
 */
class SalaryActions
{
    use TranslatesDomainErrors;

    private const COMPONENT_FIELDS = ['BASIC_COMPONENT_EXISTS' => 'is_basic', 'COMPONENT_IN_USE' => 'calculation'];

    public function __construct(
        private readonly SalaryComponentService $components,
        private readonly SalaryStructureService $structures,
        private readonly EmployeeSalaryService $salaries,
        private readonly CompanyContext $context,
        private readonly AccessScope $scope,
    ) {}

    /** @param array<string, mixed> $data */
    public function saveComponent(User $actor, ?SalaryComponent $component, array $data): SalaryComponent
    {
        return $this->domain(fn () => $component
            ? $this->components->update($actor, $component, $data)
            : $this->components->create($actor, $data), self::COMPONENT_FIELDS);
    }

    public function deleteComponent(User $actor, SalaryComponent $component): void
    {
        $this->components->delete($actor, $component);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function saveStructure(User $actor, ?SalaryStructure $structure, array $data, array $lines): SalaryStructure
    {
        return $this->domain(fn () => $this->structures->save($actor, $structure, $data, $lines));
    }

    public function deleteStructure(User $actor, SalaryStructure $structure): void
    {
        $this->structures->delete($actor, $structure);
    }

    /**
     * Assign a structure to several employees from a date. Each employee is its own transaction, so one conflict
     * (an existing later change) never blocks the others.
     *
     * @param  list<int>  $employeeIds
     * @return array{done: list<string>, failed: array<string, string>, warnings: list<string>}
     */
    public function assign(User $actor, SalaryStructure $structure, array $employeeIds, string $effectiveFrom, ?string $reason): array
    {
        $result = ['done' => [], 'failed' => [], 'warnings' => []];
        $employees = Employee::query()->where('company_id', $this->context->id())->whereIn('id', $employeeIds)->get()->keyBy('id');
        if ($employees->isEmpty()) {
            throw ValidationException::withMessages(['employee_ids' => 'Choose at least one employee.']);
        }

        foreach ($employeeIds as $id) {
            $employee = $employees->get($id);
            if (! $employee) {
                continue;
            }
            try {
                $out = $this->structures->assign($actor, $structure, $employee, $effectiveFrom, $reason);
                $result['done'][] = $employee->full_name;
                foreach ($out['warnings'] as $w) {
                    $result['warnings'][] = $employee->full_name.': '.($w['message'] ?? $w['code']);
                }
            } catch (ApiException $e) {
                if ($e->errorCode === 'STRUCTURE_EMPTY' || $e->status === 403) {
                    throw $e;
                }
                $result['failed'][$employee->full_name] = $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Change one employee's salary from a date (append-only: the service closes the old row and opens a new one).
     *
     * @param  list<array<string, mixed>>  $changes
     * @return array{created: list<mixed>, ended: int, warnings: list<array<string, mixed>>}
     */
    public function change(User $actor, Employee $employee, string $effectiveFrom, array $changes, ?string $reason): array
    {
        abort_unless($employee->company_id === $this->context->id(), 404);

        return $this->domain(fn () => $this->salaries->change($actor, $employee, $effectiveFrom, $changes, $reason), [
            'SALARY_CHANGE_CONFLICT' => 'effective_from', 'NO_CHANGES' => 'effective_from',
        ]);
    }
}
