<?php

namespace App\Services\Overtime;

use App\Enums\EmploymentType;
use App\Exceptions\ApiException;
use App\Models\Department;
use App\Models\OvertimePayrollInclusion;
use App\Models\OvertimeRule;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * Overtime rule CRUD. Editing a rule never changes finalized payroll: every inclusion stores the rule snapshot
 * it was calculated with. A rule that has paid overtime cannot be deleted (deactivate or end-date it instead).
 */
class OvertimeRuleService
{
    public function __construct(private readonly CompanyContext $context, private readonly ActivityLogger $activity) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): OvertimeRule
    {
        $fields = $this->validate($data, null);

        return DB::transaction(function () use ($actor, $fields) {
            $rule = OvertimeRule::query()->create($fields + ['company_id' => $this->context->id(), 'created_by' => $actor->id]);
            $this->activity->log('overtime_rule.created', $rule, null, $this->loggable($rule), null, $actor);

            return $rule->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, OvertimeRule $rule, array $data): OvertimeRule
    {
        $fields = $this->validate($data, $rule);

        return DB::transaction(function () use ($actor, $rule, $fields) {
            $locked = OvertimeRule::query()->whereKey($rule->id)->lockForUpdate()->firstOrFail();
            $locked->fill($fields);
            [$old, $new] = $this->activity->diff($locked);
            if ($new !== []) {
                $locked->save();
                $this->activity->log('overtime_rule.updated', $locked, $old, $new, null, $actor);
            }

            return $locked->refresh();
        });
    }

    public function delete(User $actor, OvertimeRule $rule): void
    {
        DB::transaction(function () use ($actor, $rule) {
            $locked = OvertimeRule::query()->whereKey($rule->id)->lockForUpdate()->firstOrFail();
            if (OvertimePayrollInclusion::query()->where('overtime_rule_id', $locked->id)->exists()) {
                throw ApiException::make('RULE_IN_USE', 'This rule has paid overtime. Deactivate it or set an end date instead.', 409);
            }
            $this->activity->log('overtime_rule.deleted', $locked, $this->loggable($locked), null, null, $actor);
            $locked->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data, ?OvertimeRule $existing): array
    {
        $partial = $existing !== null;
        $req = $partial ? 'sometimes' : 'required';
        $fields = Validator::make($data, [
            'name' => [$req, 'string', 'max:120'],
            'effective_from' => [$req, 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d'],
            'employment_type' => ['nullable', 'in:'.implode(',', array_column(EmploymentType::cases(), 'value'))],
            'department_id' => ['nullable', 'integer'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'rate_basis' => ['sometimes', 'in:'.implode(',', OvertimeRuleConfig::BASES)],
            'rate_divisor' => ['sometimes', 'numeric', 'gt:0', 'max:1000'],
            'multiplier_tiers' => [$req, 'array', 'min:1'],
            'rounding_minutes' => ['sometimes', 'integer', 'in:1,5,6,10,15,30,60'],
            'rounding_direction' => ['sometimes', 'in:'.implode(',', OvertimeRuleConfig::DIRECTIONS)],
            'min_minutes_per_request' => ['sometimes', 'integer', 'min:0', 'max:720'],
            'max_minutes_per_day' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'max_minutes_per_period' => ['nullable', 'integer', 'min:1', 'max:44640'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ])->validate();

        $from = $fields['effective_from'] ?? $existing?->effective_from?->toDateString();
        $to = array_key_exists('effective_to', $fields) ? $fields['effective_to'] : $existing?->effective_to?->toDateString();
        if ($to !== null && $from !== null && $to < $from) {
            throw ApiException::make('VALIDATION_ERROR', 'The given data was invalid.', 422, ['effective_to' => ['The end date must be on or after the start date.']]);
        }
        if (! empty($fields['department_id'])
            && ! Department::query()->whereKey($fields['department_id'])->where('company_id', $this->context->id())->exists()) {
            throw ApiException::make('VALIDATION_ERROR', 'The given data was invalid.', 422, ['department_id' => ['Unknown department.']]);
        }
        if (isset($fields['multiplier_tiers'])) {
            try {
                OvertimeRuleConfig::tiersFromJson($fields['multiplier_tiers']);
            } catch (InvalidArgumentException $e) {
                throw ApiException::make('VALIDATION_ERROR', 'The given data was invalid.', 422, ['multiplier_tiers' => [$e->getMessage()]]);
            }
        }

        return $fields;
    }

    /** @return array<string, mixed> */
    private function loggable(OvertimeRule $rule): array
    {
        return $rule->only(['name', 'rate_basis', 'rate_divisor', 'rounding_minutes', 'rounding_direction', 'max_minutes_per_day', 'max_minutes_per_period', 'is_active'])
            + ['effective_from' => $rule->effective_from?->toDateString(), 'multiplier_tiers' => $rule->multiplier_tiers];
    }
}
