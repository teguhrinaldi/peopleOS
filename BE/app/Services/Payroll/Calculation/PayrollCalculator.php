<?php

namespace App\Services\Payroll\Calculation;

use App\Enums\SalaryCalculation;
use App\Enums\SalaryComponentKind;
use App\Services\Payroll\Earnings\EarningLine;
use App\Services\Payroll\Money;

/**
 * Pure payroll maths for ONE employee: no database, no clock, no settings. Deterministic for a given input.
 *
 *   basic       = sum of the FIXED components flagged is_basic
 *   earnings    = each EARNING component (+ manual earning entries), rounded per line
 *   gross       = sum of earnings
 *   deductions  = each DEDUCTION component (+ manual deduction entries), rounded per line;
 *                 PERCENT_OF_GROSS uses the gross above
 *   net         = gross - deductions        (negative is returned and flagged, never clamped)
 *
 * Rules per calculation type:
 *   FIXED                   amount
 *   PERCENT_OF_BASIC        basic x rate / 100
 *   PERCENT_OF_GROSS        gross x rate / 100                       (deductions only)
 *   PER_PRESENT_DAY         amount x present days
 *   PER_OVERTIME_HOUR       amount x approved overtime minutes / 60
 *   UNPAID_LEAVE_DEDUCTION  basic / working days x unpaid days x (rate ?? 100) / 100   (deductions only)
 *
 * Earning-source lines (PayrollInput::sourceLines, e.g. the overtime rule engine) are added as computed by their
 * source, before PERCENT_OF_GROSS deductions. With overtimeComponentSuperseded, PER_OVERTIME_HOUR components are
 * skipped (warning OVERTIME_COMPONENT_SUPERSEDED:<code>) so overtime is never counted twice.
 *
 * Every line is rounded half-up to the currency decimals BEFORE summing, so a payslip always adds up.
 * Lines whose amount rounds to zero are omitted (the inputs stay on the item).
 */
final class PayrollCalculator
{
    public function calculate(PayrollInput $input): PayrollResult
    {
        $d = $input->decimals;
        $warnings = [];
        $components = $this->ordered($input->components);

        $basic = '0';
        foreach ($components as $c) {
            if ($c->isBasic && $c->kind === SalaryComponentKind::Earning && $c->calculation === SalaryCalculation::Fixed) {
                $basic = Money::add($basic, Money::round($c->amount ?? '0', $d));
            }
        }
        $basic = Money::round($basic, $d);

        if (! array_filter($components, fn (ComponentValue $c) => $c->isBasic) && $input->manualEntries === [] && $input->sourceLines === []) {
            $warnings[] = 'NO_BASIC_SALARY';
        }

        $lines = [];
        $gross = '0';
        foreach ($components as $c) {
            if ($c->kind !== SalaryComponentKind::Earning) {
                continue;
            }
            if ($c->calculation->deductionOnly()) {
                $warnings[] = 'INVALID_EARNING_CALCULATION:'.$c->code;

                continue;
            }
            if ($input->overtimeComponentSuperseded && $c->calculation === SalaryCalculation::PerOvertimeHour) {
                $warnings[] = 'OVERTIME_COMPONENT_SUPERSEDED:'.$c->code;

                continue;
            }
            $line = $this->line($c, $input, $basic, $gross);
            if ($line) {
                $lines[] = $line;
                $gross = Money::add($gross, $line->amount);
            }
        }
        foreach ($input->sourceLines as $e) {
            if ($e->kind === SalaryComponentKind::Earning && ($line = $this->sourced($e, $d))) {
                $lines[] = $line;
                $gross = Money::add($gross, $line->amount);
            }
        }
        foreach ($input->manualEntries as $i => $m) {
            if ($m->kind === SalaryComponentKind::Earning && ($line = $this->manual($m, $d, 1000 + $i))) {
                $lines[] = $line;
                $gross = Money::add($gross, $line->amount);
            }
        }
        $gross = Money::round($gross, $d);

        $deductions = '0';
        foreach ($components as $c) {
            if ($c->kind !== SalaryComponentKind::Deduction) {
                continue;
            }
            if ($c->calculation === SalaryCalculation::UnpaidLeaveDeduction && $input->workingDays <= 0
                && Money::cmp($input->unpaidLeaveDays, '0') > 0) {
                $warnings[] = 'NO_WORKING_DAYS';

                continue;
            }
            $line = $this->line($c, $input, $basic, $gross);
            if ($line) {
                $lines[] = $line;
                $deductions = Money::add($deductions, $line->amount);
            }
        }
        foreach ($input->sourceLines as $e) {
            if ($e->kind === SalaryComponentKind::Deduction && ($line = $this->sourced($e, $d))) {
                $lines[] = $line;
                $deductions = Money::add($deductions, $line->amount);
            }
        }
        foreach ($input->manualEntries as $i => $m) {
            if ($m->kind === SalaryComponentKind::Deduction && ($line = $this->manual($m, $d, 2000 + $i))) {
                $lines[] = $line;
                $deductions = Money::add($deductions, $line->amount);
            }
        }
        $deductions = Money::round($deductions, $d);

        $net = Money::round(Money::sub($gross, $deductions), $d);
        if (Money::isNegative($net)) {
            $warnings[] = 'NEGATIVE_NET';
        }

        return new PayrollResult($lines, $basic, $gross, $deductions, $net, array_values(array_unique($warnings)));
    }

    private function line(ComponentValue $c, PayrollInput $in, string $basic, string $gross): ?CalculatedLine
    {
        $d = $in->decimals;
        $amount = Money::of($c->amount ?? '0');
        $rate = $c->rate !== null ? Money::of($c->rate) : null;
        $fmt = fn (string $v) => Money::format($v, $d);

        [$value, $quantity, $unit, $base, $description] = match ($c->calculation) {
            SalaryCalculation::Fixed => [$amount, null, null, null, null],
            SalaryCalculation::PercentOfBasic => [
                Money::div(Money::mul($basic, $rate ?? '0'), '100'), null, null, $basic,
                Money::trimRate($rate ?? '0').'% of '.$fmt($basic),
            ],
            SalaryCalculation::PercentOfGross => [
                Money::div(Money::mul($gross, $rate ?? '0'), '100'), null, null, $gross,
                Money::trimRate($rate ?? '0').'% of '.$fmt($gross),
            ],
            SalaryCalculation::PerPresentDay => [
                Money::mul($amount, (string) $in->presentDays), (string) $in->presentDays, $amount, null,
                $in->presentDays.' day'.($in->presentDays === 1 ? '' : 's').' x '.$fmt($amount),
            ],
            SalaryCalculation::PerOvertimeHour => (function () use ($amount, $in, $fmt) {
                $hours = Money::round(Money::div((string) $in->overtimeMinutes, '60'), 2);

                return [
                    Money::div(Money::mul($amount, (string) $in->overtimeMinutes), '60'), $hours, $amount, null,
                    $hours.' h x '.$fmt($amount),
                ];
            })(),
            SalaryCalculation::UnpaidLeaveDeduction => (function () use ($basic, $in, $rate, $fmt) {
                $daily = Money::div($basic, (string) $in->workingDays);
                $value = Money::mul($daily, $in->unpaidLeaveDays);
                if ($rate !== null) {
                    $value = Money::div(Money::mul($value, $rate), '100');
                }
                $days = Money::trimRate($in->unpaidLeaveDays);

                return [
                    $value, Money::round($in->unpaidLeaveDays, 2), Money::round($daily, 2), $basic,
                    $fmt($basic).' / '.$in->workingDays.' working days x '.$days.' day'.($days === '1' ? '' : 's')
                        .($rate !== null && Money::cmp($rate, '100') !== 0 ? ' x '.Money::trimRate($rate).'%' : ''),
                ];
            })(),
        };

        $value = Money::round($value, $d);
        if (Money::isZero($value)) {
            return null;
        }

        return new CalculatedLine(
            componentId: $c->componentId,
            code: $c->code,
            name: $c->name,
            kind: $c->kind,
            calculation: $c->calculation->value,
            amount: $value,
            rate: $rate !== null && $c->calculation->usesRate() ? $rate : null,
            quantity: $quantity !== null ? Money::round($quantity, 2) : null,
            unitAmount: $unit !== null ? Money::round($unit, 2) : null,
            baseAmount: $base !== null ? Money::round($base, 2) : null,
            description: $description,
            sortOrder: $c->sortOrder,
        );
    }

    /** A line proposed by an earning source: taken as computed by the source, only rounded. */
    private function sourced(EarningLine $e, int $d): ?CalculatedLine
    {
        $value = Money::round($e->amount, $d);
        if (Money::isZero($value)) {
            return null;
        }

        return new CalculatedLine(
            componentId: null,
            code: $e->code,
            name: $e->name,
            kind: $e->kind,
            calculation: 'SOURCE:'.strtoupper($e->sourceKey),
            amount: $value,
            quantity: $e->quantity,
            unitAmount: $e->unitAmount,
            baseAmount: $e->baseAmount,
            description: $e->description,
            sortOrder: $e->sortOrder,
            source: $e,
        );
    }

    private function manual(ManualEntry $m, int $d, int $sort): ?CalculatedLine
    {
        $value = Money::round($m->amount, $d);
        if (Money::isZero($value)) {
            return null;
        }

        return new CalculatedLine(
            componentId: null,
            code: 'ADJUSTMENT',
            name: $m->label,
            kind: $m->kind,
            calculation: SalaryCalculation::MANUAL_ADJUSTMENT,
            amount: $value,
            description: 'Manual adjustment',
            adjustmentId: $m->adjustmentId,
            adjustmentReason: $m->reason,
            sortOrder: $sort,
        );
    }

    /** @param list<ComponentValue> $components @return list<ComponentValue> */
    private function ordered(array $components): array
    {
        usort($components, fn (ComponentValue $a, ComponentValue $b) => [$b->isBasic, $a->sortOrder, $a->code] <=> [$a->isBasic, $b->sortOrder, $b->code]);

        return $components;
    }
}
