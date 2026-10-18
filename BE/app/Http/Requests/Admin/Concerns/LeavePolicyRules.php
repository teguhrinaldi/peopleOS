<?php

namespace App\Http\Requests\Admin\Concerns;

/** Validation for leave policy fields, optionally nested under a prefix (e.g. "policy."). Days are in half-day steps. */
trait LeavePolicyRules
{
    private const HALF_DAYS = 'regex:/^\d{1,3}(\.[05])?$/';

    /** @return array<string, mixed> */
    protected function policyRules(string $prefix = ''): array
    {
        return [
            "{$prefix}annual_allowance" => ['nullable', 'numeric', 'between:0,366', self::HALF_DAYS],
            "{$prefix}prorate_first_year" => ['boolean'],
            "{$prefix}carry_over_max" => ['required', 'numeric', 'between:0,366', self::HALF_DAYS],
            "{$prefix}allow_negative" => ['boolean'],
            "{$prefix}max_negative_days" => ['required', 'numeric', 'between:0,366', self::HALF_DAYS],
            "{$prefix}allow_half_day" => ['boolean'],
            "{$prefix}allow_overlap" => ['boolean'],
            "{$prefix}min_notice_days" => ['required', 'integer', 'between:0,365'],
            "{$prefix}max_past_days" => ['required', 'integer', 'between:0,365'],
            "{$prefix}max_consecutive_days" => ['nullable', 'integer', 'between:1,366'],
            "{$prefix}requires_reason" => ['boolean'],
        ];
    }

    /** Checkbox values become real booleans; empty number fields become null (or 0 where a value is required). */
    protected function normalizePolicy(string $key = ''): void
    {
        $data = $key === '' ? $this->all() : (array) $this->input($key, []);
        foreach (['prorate_first_year', 'allow_negative', 'allow_half_day', 'allow_overlap', 'requires_reason'] as $flag) {
            $data[$flag] = filter_var($data[$flag] ?? false, FILTER_VALIDATE_BOOLEAN);
        }
        foreach (['annual_allowance', 'max_consecutive_days'] as $optional) {
            $data[$optional] = ($data[$optional] ?? '') === '' ? null : $data[$optional];
        }
        foreach (['carry_over_max', 'max_negative_days', 'min_notice_days', 'max_past_days'] as $zero) {
            $data[$zero] = ($data[$zero] ?? '') === '' ? 0 : $data[$zero];
        }
        if (! $data['allow_negative']) {
            $data['max_negative_days'] = 0;
        }

        $this->merge($key === '' ? $data : [$key => $data]);
    }

    /** @return array<string, string> */
    protected function policyMessages(string $prefix = ''): array
    {
        return [
            "{$prefix}annual_allowance.regex" => 'Use whole or half days (e.g. 12 or 12.5).',
            "{$prefix}carry_over_max.regex" => 'Use whole or half days.',
            "{$prefix}max_negative_days.regex" => 'Use whole or half days.',
        ];
    }

    /** @return list<string> */
    protected function policyFields(): array
    {
        return ['annual_allowance', 'prorate_first_year', 'carry_over_max', 'allow_negative', 'max_negative_days', 'allow_half_day',
            'allow_overlap', 'min_notice_days', 'max_past_days', 'max_consecutive_days', 'requires_reason'];
    }
}
