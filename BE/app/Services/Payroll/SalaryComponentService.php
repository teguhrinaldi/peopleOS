<?php

namespace App\Services\Payroll;

use App\Enums\SalaryCalculation;
use App\Enums\SalaryComponentKind;
use App\Exceptions\ApiException;
use App\Models\EmployeeSalaryComponent;
use App\Models\PayrollItemLine;
use App\Models\SalaryComponent;
use App\Models\SalaryStructureComponent;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

/** The company catalog of earnings / deductions. */
class SalaryComponentService
{
    private const FIELDS = ['code', 'name', 'kind', 'calculation', 'is_basic', 'default_amount', 'default_rate', 'description', 'sort_order', 'is_active'];

    /** Changing these would change the meaning of stored employee values, so they freeze once used. */
    private const SEMANTIC = ['kind', 'calculation', 'is_basic'];

    public function __construct(
        private readonly CompanyContext $context,
        private readonly ActivityLogger $activity,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): SalaryComponent
    {
        return DB::transaction(function () use ($actor, $data) {
            $component = new SalaryComponent(['company_id' => $this->context->id(), 'is_active' => true, 'sort_order' => 0]);
            $component->fill(array_intersect_key($data, array_flip(self::FIELDS)));
            $component->code = strtoupper((string) $component->code);
            $this->validate($component);
            $component->save();

            $this->activity->log('salary_component.created', $component, null, $component->only(self::FIELDS), null, $actor);

            return $component;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, SalaryComponent $component, array $data): SalaryComponent
    {
        return DB::transaction(function () use ($actor, $component, $data) {
            $component = SalaryComponent::query()->whereKey($component->id)->lockForUpdate()->firstOrFail();
            $component->fill(array_intersect_key($data, array_flip(self::FIELDS)));
            if ($component->isDirty('code')) {
                $component->code = strtoupper((string) $component->code);
            }

            if ($component->isDirty(self::SEMANTIC) && $this->isUsed($component)) {
                throw ApiException::make('COMPONENT_IN_USE', 'Kind, calculation and the basic flag cannot change once the component holds employee values or payroll lines. Create a new component instead.', 409);
            }
            $this->validate($component);

            [$old, $new] = $this->activity->diff($component);
            if ($new !== []) {
                $component->save();
                $this->activity->log('salary_component.updated', $component, $old, $new, null, $actor);
            }

            return $component;
        });
    }

    /** Only a never-used component may be deleted; otherwise deactivate it. */
    public function delete(User $actor, SalaryComponent $component): void
    {
        DB::transaction(function () use ($actor, $component) {
            $component = SalaryComponent::query()->whereKey($component->id)->lockForUpdate()->firstOrFail();
            if ($this->isUsed($component) || SalaryStructureComponent::query()->where('salary_component_id', $component->id)->exists()) {
                throw ApiException::make('COMPONENT_IN_USE', 'This component is used by structures, employees or payroll history. Deactivate it instead.', 409);
            }
            $this->activity->log('salary_component.deleted', $component, $component->only(['code', 'name']), null, null, $actor);
            $component->delete();
        });
    }

    public function isUsed(SalaryComponent $component): bool
    {
        return EmployeeSalaryComponent::query()->where('salary_component_id', $component->id)->exists()
            || PayrollItemLine::query()->where('salary_component_id', $component->id)->exists();
    }

    private function validate(SalaryComponent $c): void
    {
        $errors = [];
        if ($c->calculation->deductionOnly() && $c->kind !== SalaryComponentKind::Deduction) {
            $errors['calculation'][] = "{$c->calculation->value} can only be used for deductions.";
        }
        if ($c->is_basic && ($c->kind !== SalaryComponentKind::Earning || $c->calculation !== SalaryCalculation::Fixed)) {
            $errors['is_basic'][] = 'The basic salary component must be a FIXED earning.';
        }
        if ($c->calculation->usesAmount() && $c->default_rate !== null) {
            $c->default_rate = null;
        }
        if (! $c->calculation->usesAmount() && $c->default_amount !== null) {
            $c->default_amount = null;
        }
        if ($errors) {
            throw ApiException::make('VALIDATION_ERROR', 'The given data was invalid.', 422, $errors);
        }

        $codeTaken = SalaryComponent::query()->where('company_id', $c->company_id)->where('code', $c->code)
            ->when($c->exists, fn ($q) => $q->whereKeyNot($c->id))->exists();
        if ($codeTaken) {
            throw ApiException::make('VALIDATION_ERROR', 'The given data was invalid.', 422, ['code' => ['This code is already used.']]);
        }

        if ($c->is_basic) {
            $otherBasic = SalaryComponent::query()->where('company_id', $c->company_id)->where('is_basic', true)
                ->when($c->exists, fn ($q) => $q->whereKeyNot($c->id))->exists();
            if ($otherBasic) {
                throw ApiException::make('BASIC_COMPONENT_EXISTS', 'The company already has a basic salary component.', 409);
            }
        }
    }
}
