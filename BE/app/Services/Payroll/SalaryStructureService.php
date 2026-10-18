<?php

namespace App\Services\Payroll;

use App\Exceptions\ApiException;
use App\Models\Employee;
use App\Models\EmployeeSalaryComponent;
use App\Models\SalaryComponent;
use App\Models\SalaryStructure;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * Salary structures are templates. Assigning one to an employee COPIES its values into the employee's
 * effective-dated history from a date (EmployeeSalaryService); later template edits never touch employees.
 * Assignment makes the structure the employee's full set: components the employee has that the structure
 * does not list are ended the day before.
 */
class SalaryStructureService
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly ActivityLogger $activity,
        private readonly EmployeeSalaryService $salaries,
    ) {}

    /**
     * @param  array{code: string, name: string, description?: ?string, is_active?: bool}  $data
     * @param  list<array{salary_component_id: int, amount?: mixed, rate?: mixed, sort_order?: int}>|null  $components
     */
    public function save(User $actor, ?SalaryStructure $structure, array $data, ?array $components): SalaryStructure
    {
        return DB::transaction(function () use ($actor, $structure, $data, $components) {
            $structure = $structure
                ? SalaryStructure::query()->whereKey($structure->id)->lockForUpdate()->firstOrFail()
                : new SalaryStructure(['company_id' => $this->context->id(), 'is_active' => true]);
            $isNew = ! $structure->exists;

            $structure->fill(array_intersect_key($data, array_flip(['code', 'name', 'description', 'is_active'])));
            $structure->code = strtoupper((string) $structure->code);
            $taken = SalaryStructure::query()->where('company_id', $structure->company_id)->where('code', $structure->code)
                ->when($structure->exists, fn ($q) => $q->whereKeyNot($structure->id))->exists();
            if ($taken) {
                throw ApiException::make('VALIDATION_ERROR', 'The given data was invalid.', 422, ['code' => ['This code is already used.']]);
            }

            [$old, $new] = $isNew ? [null, null] : $this->activity->diff($structure);
            $structure->save();

            $lineChanges = null;
            if ($components !== null) {
                $before = $structure->lines()->with('component')->get()->mapWithKeys(fn ($l) => [$l->component->code => array_filter(['amount' => $l->amount, 'rate' => $l->rate], fn ($v) => $v !== null)])->all();
                $structure->lines()->delete();
                foreach (array_values($components) as $i => $line) {
                    $component = SalaryComponent::query()->where('company_id', $this->context->id())->find($line['salary_component_id'] ?? 0);
                    if (! $component) {
                        throw ApiException::make('VALIDATION_ERROR', 'The given data was invalid.', 422, ["components.{$i}.salary_component_id" => ['Unknown salary component.']]);
                    }
                    $structure->lines()->create([
                        'salary_component_id' => $component->id,
                        'amount' => $component->calculation->usesAmount() ? ($line['amount'] ?? $component->default_amount) : null,
                        'rate' => $component->calculation->usesRate() ? ($line['rate'] ?? $component->default_rate) : null,
                        'sort_order' => (int) ($line['sort_order'] ?? $i),
                    ]);
                }
                $after = $structure->lines()->with('component')->get()->mapWithKeys(fn ($l) => [$l->component->code => array_filter(['amount' => $l->amount, 'rate' => $l->rate], fn ($v) => $v !== null)])->all();
                if ($before != $after) {
                    $lineChanges = ['before' => $before, 'after' => $after];
                }
            }

            if ($isNew) {
                $this->activity->log('salary_structure.created', $structure, null, $structure->only(['code', 'name']) + ['components' => $lineChanges['after'] ?? []], null, $actor);
            } elseif ($new !== [] || $lineChanges) {
                $this->activity->log('salary_structure.updated', $structure, ($old ?: []) + ($lineChanges ? ['components' => $lineChanges['before']] : []), ($new ?: []) + ($lineChanges ? ['components' => $lineChanges['after']] : []), null, $actor);
            }

            return $structure->load('lines.component');
        });
    }

    public function delete(User $actor, SalaryStructure $structure): void
    {
        DB::transaction(function () use ($actor, $structure) {
            $this->activity->log('salary_structure.deleted', $structure, $structure->only(['code', 'name']), null, null, $actor);
            $structure->lines()->delete();
            $structure->delete(); // employee history keeps its values; salary_structure_id provenance becomes NULL
        });
    }

    /**
     * Copy the structure into the employee's history from $effectiveFrom.
     *
     * @param  array<int, array{amount?: mixed, rate?: mixed}>  $overrides  salary_component_id => value
     * @return array{created: list<EmployeeSalaryComponent>, ended: int, warnings: list<array<string, mixed>>}
     */
    public function assign(User $actor, SalaryStructure $structure, Employee $employee, string $effectiveFrom, ?string $reason = null, array $overrides = []): array
    {
        $structure->loadMissing('lines.component');
        if ($structure->lines->isEmpty()) {
            throw ApiException::make('STRUCTURE_EMPTY', 'This salary structure has no components.', 422);
        }

        $changes = [];
        foreach ($structure->lines as $line) {
            $override = $overrides[$line->salary_component_id] ?? [];
            $changes[] = [
                'salary_component_id' => $line->salary_component_id,
                'amount' => $override['amount'] ?? $line->amount,
                'rate' => $override['rate'] ?? $line->rate,
            ];
        }

        $listed = $structure->lines->pluck('salary_component_id')->all();
        $others = EmployeeSalaryComponent::query()->where('employee_id', $employee->id)->effectiveOn($effectiveFrom)
            ->whereNotIn('salary_component_id', $listed)->pluck('salary_component_id');
        foreach ($others as $componentId) {
            $changes[] = ['salary_component_id' => $componentId, 'remove' => true];
        }

        return $this->salaries->change($actor, $employee, $effectiveFrom, $changes, $reason ?? "Assigned salary structure {$structure->name}", $structure->id);
    }
}
