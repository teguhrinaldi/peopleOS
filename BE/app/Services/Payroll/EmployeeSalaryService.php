<?php

namespace App\Services\Payroll;

use App\Enums\PayrollStatus;
use App\Exceptions\ApiException;
use App\Models\Employee;
use App\Models\EmployeeSalaryComponent;
use App\Models\PayrollItem;
use App\Models\SalaryComponent;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Effective-dated employee salary values. A change never overwrites history:
 *   - the row in force on effective_from is closed (effective_to = effective_from - 1 day)
 *   - a new row starts on effective_from (open-ended)
 * A change may not be dated on/before an existing later change of the same component (409 SALARY_CHANGE_CONFLICT),
 * so the timeline only ever grows forward. Back-dated changes are allowed but never touch finalized payroll;
 * the response lists the finalized periods they fall into so HR can raise an adjustment.
 */
class EmployeeSalaryService
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly ActivityLogger $activity,
    ) {}

    /** @return Collection<int, EmployeeSalaryComponent> full history, newest first */
    public function history(Employee $employee): Collection
    {
        return EmployeeSalaryComponent::query()->with(['component', 'creator'])
            ->where('employee_id', $employee->id)
            ->orderByDesc('effective_from')->orderBy('salary_component_id')->get();
    }

    /** @return Collection<int, EmployeeSalaryComponent> values in force on a date */
    public function valuesOn(Employee $employee, string $date): Collection
    {
        return EmployeeSalaryComponent::query()->with('component')
            ->where('employee_id', $employee->id)->effectiveOn($date)
            ->get()->sortBy(fn ($r) => [$r->component->is_basic ? 0 : 1, $r->component->sort_order, $r->component->id])->values();
    }

    /**
     * @param  list<array{salary_component_id: int, amount?: string|int|float|null, rate?: string|int|float|null, remove?: bool}>  $changes
     * @return array{created: list<EmployeeSalaryComponent>, ended: int, warnings: list<array<string, mixed>>}
     */
    public function change(User $actor, Employee $employee, string $effectiveFrom, array $changes, ?string $reason = null, ?int $structureId = null): array
    {
        if ($employee->company_id !== $this->context->id()) {
            throw ApiException::make('NOT_FOUND', 'The requested resource was not found.', 404);
        }
        $from = CarbonImmutable::parse($effectiveFrom)->toDateString();
        $dayBefore = CarbonImmutable::parse($from)->subDay()->toDateString();

        return DB::transaction(function () use ($actor, $employee, $from, $dayBefore, $changes, $reason, $structureId) {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();

            $created = [];
            $ended = 0;
            $log = [];
            foreach ($changes as $i => $change) {
                $component = SalaryComponent::query()->where('company_id', $this->context->id())->find($change['salary_component_id'] ?? 0);
                if (! $component) {
                    throw ApiException::make('VALIDATION_ERROR', 'The given data was invalid.', 422, ["components.{$i}.salary_component_id" => ['Unknown salary component.']]);
                }
                $remove = (bool) ($change['remove'] ?? false);

                $rows = EmployeeSalaryComponent::query()->where('employee_id', $employee->id)
                    ->where('salary_component_id', $component->id)->lockForUpdate()->get();
                if ($rows->contains(fn ($r) => $r->effective_from->toDateString() >= $from)) {
                    throw ApiException::make('SALARY_CHANGE_CONFLICT', "{$component->name} already has a change effective on or after {$from}.", 409, ['component' => $component->code]);
                }
                $current = $rows->first(fn ($r) => $r->effective_to === null || $r->effective_to->toDateString() >= $from);

                if ($remove) {
                    if (! $current) {
                        continue;
                    }
                    $current->forceFill(['effective_to' => $dayBefore])->save();
                    $ended++;
                    $log[] = ['component' => $component->code, 'from' => $this->value($current), 'to' => null];

                    continue;
                }

                if (! $component->is_active) {
                    throw ApiException::make('COMPONENT_INACTIVE', "{$component->name} is inactive.", 422);
                }
                [$amount, $rate] = $this->normalize($component, $change, $i);

                if ($current && $this->same($current, $amount, $rate)) {
                    continue; // no-op: identical value already in force
                }
                if ($current) {
                    $current->forceFill(['effective_to' => $dayBefore])->save();
                }

                $created[] = EmployeeSalaryComponent::query()->create([
                    'employee_id' => $employee->id,
                    'salary_component_id' => $component->id,
                    'amount' => $amount,
                    'rate' => $rate,
                    'effective_from' => $from,
                    'salary_structure_id' => $structureId,
                    'reason' => $reason,
                    'created_by' => $actor->id,
                ]);
                $log[] = ['component' => $component->code, 'from' => $current ? $this->value($current) : null, 'to' => array_filter(['amount' => $amount, 'rate' => $rate], fn ($v) => $v !== null)];
            }

            if ($log === []) {
                throw ApiException::make('NO_CHANGES', 'These values are already in force; nothing was changed.', 422);
            }

            $this->activity->log('employee_salary.changed', $employee, null, [
                'effective_from' => $from,
                'reason' => $reason,
                'salary_structure_id' => $structureId,
                'changes' => $log,
            ], null, $actor);

            return ['created' => $created, 'ended' => $ended, 'warnings' => $this->finalizedOverlap($employee, $from)];
        });
    }

    /** @return array{0: ?string, 1: ?string} */
    private function normalize(SalaryComponent $component, array $change, int $i): array
    {
        $amount = $change['amount'] ?? null;
        $rate = $change['rate'] ?? null;
        $calc = $component->calculation;

        if ($calc->usesAmount()) {
            $amount ??= $component->default_amount;
            if ($amount === null || Money::isNegative(Money::of($amount))) {
                throw ApiException::make('VALIDATION_ERROR', 'The given data was invalid.', 422, ["components.{$i}.amount" => ['An amount of 0 or more is required.']]);
            }

            return [Money::round(Money::of($amount), 2), null];
        }

        $rate ??= $component->default_rate;
        if ($rate === null && $calc->value === 'UNPAID_LEAVE_DEDUCTION') {
            return [null, null]; // 100 % of the daily rate
        }
        if ($rate === null || Money::isNegative(Money::of($rate)) || Money::cmp(Money::of($rate), '1000') > 0) {
            throw ApiException::make('VALIDATION_ERROR', 'The given data was invalid.', 422, ["components.{$i}.rate" => ['A percentage between 0 and 1000 is required.']]);
        }

        return [null, bcadd(Money::of($rate), '0', 4)];
    }

    private function same(EmployeeSalaryComponent $row, ?string $amount, ?string $rate): bool
    {
        $eq = fn ($a, $b) => ($a === null && $b === null) || ($a !== null && $b !== null && Money::cmp((string) $a, (string) $b) === 0);

        return $eq($row->amount, $amount) && $eq($row->rate, $rate);
    }

    /** @return array<string, string> */
    private function value(EmployeeSalaryComponent $row): array
    {
        return array_filter(['amount' => $row->amount, 'rate' => $row->rate], fn ($v) => $v !== null);
    }

    /** @return list<array<string, mixed>> */
    private function finalizedOverlap(Employee $employee, string $from): array
    {
        $codes = PayrollItem::query()
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_items.payroll_run_id')
            ->join('payroll_periods', 'payroll_periods.id', '=', 'payroll_runs.payroll_period_id')
            ->where('payroll_items.employee_id', $employee->id)
            ->whereIn('payroll_runs.status', [PayrollStatus::Finalized->value, PayrollStatus::Paid->value])
            ->where('payroll_periods.end_date', '>=', $from)
            ->orderBy('payroll_periods.start_date')
            ->pluck('payroll_periods.code')->all();

        return $codes === [] ? [] : [[
            'code' => 'AFFECTS_FINALIZED_PAYROLL',
            'message' => 'This change is dated inside finalized payroll, which is never recalculated. Create an adjustment period to settle any difference.',
            'periods' => $codes,
        ]];
    }
}
