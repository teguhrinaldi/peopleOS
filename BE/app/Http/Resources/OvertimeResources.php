<?php

namespace App\Http\Resources;

use App\Models\OvertimePayrollInclusion;
use App\Models\OvertimeRule;
use App\Support\Fmt;

/** Plain array mappers for overtime rules and payroll inclusions. Money as decimal strings. */
final class OvertimeResources
{
    /** @return array<string, mixed> */
    public static function rule(OvertimeRule $r): array
    {
        return [
            'id' => $r->id,
            'name' => $r->name,
            'effective_from' => Fmt::date($r->effective_from),
            'effective_to' => Fmt::date($r->effective_to),
            'employment_type' => $r->employment_type,
            'department_id' => $r->department_id,
            'priority' => $r->priority,
            'rate_basis' => $r->rate_basis,
            'rate_divisor' => $r->rate_divisor,
            'multiplier_tiers' => $r->multiplier_tiers,
            'rounding_minutes' => $r->rounding_minutes,
            'rounding_direction' => $r->rounding_direction,
            'min_minutes_per_request' => $r->min_minutes_per_request,
            'max_minutes_per_day' => $r->max_minutes_per_day,
            'max_minutes_per_period' => $r->max_minutes_per_period,
            'is_active' => $r->is_active,
            'notes' => $r->notes,
            'created_at' => Fmt::iso($r->created_at),
            'updated_at' => Fmt::iso($r->updated_at),
        ];
    }

    /**
     * Payroll reference of an included request. Money and payslip only for the employee themself or payroll/payslip
     * viewers (decided by the caller); the payslip only once the run is released (finalized / paid).
     *
     * @return array<string, mixed>
     */
    public static function inclusion(OvertimePayrollInclusion $i): array
    {
        $i->loadMissing(['run.period', 'line.item.payslip']);
        $run = $i->run;
        $slip = $run?->status->isReleased() ? $i->line?->item?->payslip : null;

        return [
            'payroll_period' => $run ? ['id' => $run->period->id, 'code' => $run->period->code, 'name' => $run->period->name, 'status' => $run->status->value] : null,
            'payroll_item_id' => $i->line?->payroll_item_id,
            'payroll_item_line_id' => $i->payroll_item_line_id,
            'minutes' => $i->minutes,
            'hours' => number_format($i->minutes / 60, 2, '.', ''),
            'day_type' => $i->day_type,
            'rate' => $i->rate,
            'multiplier' => $i->multiplier,
            'amount' => $i->amount,
            'currency' => $run?->currency,
            'segments' => $i->segments,
            'locked' => $i->locked_at !== null,
            'payslip' => $slip ? ['id' => $slip->id, 'payslip_number' => $slip->payslip_number] : null,
        ];
    }
}
