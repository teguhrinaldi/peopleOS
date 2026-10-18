<?php

namespace App\Services\Overtime;

use App\Services\Payroll\Money;

/**
 * Pure overtime maths for ONE overtime request: no database, no clock, no settings. bcmath strings only.
 *
 *   hourly rate   = round(basis / divisor, 2)                          basis = basic (or all fixed earnings)
 *   minutes       = eligible minutes (min(approved, verified)), rounded to rule.rounding_minutes (DOWN|NEAREST|UP)
 *                   -> 0 when below rule.min_minutes_per_request
 *                   -> capped by what is left of max_minutes_per_day (minutes already paid on that work date)
 *                   -> capped by what is left of max_minutes_per_period
 *   segments      = the payable minutes split over the day type's multiplier bands, counted from the minutes
 *                   already paid on the same work date (so 2 requests on one day share the "first hour" band)
 *   segment amt   = round(rate x minutes x multiplier / 60, currency decimals)          (rounded per segment)
 *   amount        = sum of segment amounts
 */
final class OvertimeCalculator
{
    public function hourlyRate(string $basis, OvertimeRuleConfig $rule): string
    {
        return Money::round(Money::div($basis, $rule->rateDivisor), 2);
    }

    public function roundMinutes(int $minutes, OvertimeRuleConfig $rule): int
    {
        $unit = $rule->roundingMinutes;
        if ($unit <= 1 || $minutes <= 0) {
            return max(0, $minutes);
        }

        return match ($rule->roundingDirection) {
            'UP' => (int) ceil($minutes / $unit) * $unit,
            'NEAREST' => intdiv($minutes + intdiv($unit, 2), $unit) * $unit,
            default => intdiv($minutes, $unit) * $unit,
        };
    }

    public function calculate(
        int $eligibleMinutes,
        string $dayType,
        string $hourlyRate,
        OvertimeRuleConfig $rule,
        int $decimals,
        int $minutesAlreadyOnDay = 0,
        int $minutesAlreadyInPeriod = 0,
    ): OvertimeCalculation {
        $notes = [];
        $rounded = $this->roundMinutes($eligibleMinutes, $rule);
        if ($rounded !== $eligibleMinutes) {
            $notes[] = 'ROUNDED';
        }

        $payable = $rounded;
        if ($payable > 0 && $payable < $rule->minMinutesPerRequest) {
            $payable = 0;
            $notes[] = 'BELOW_MINIMUM';
        }
        if ($rule->maxMinutesPerDay !== null && $payable > ($left = max(0, $rule->maxMinutesPerDay - $minutesAlreadyOnDay))) {
            $payable = $left;
            $notes[] = 'CAPPED_DAY';
        }
        if ($rule->maxMinutesPerPeriod !== null && $payable > ($left = max(0, $rule->maxMinutesPerPeriod - $minutesAlreadyInPeriod))) {
            $payable = $left;
            $notes[] = 'CAPPED_PERIOD';
        }

        $bands = $rule->tiers[$dayType] ?? [];
        if ($payable > 0 && $bands === []) {
            $notes[] = 'NO_TIER_FOR_DAY_TYPE';
            $payable = 0;
        }

        $segments = [];
        $total = '0';
        $position = $minutesAlreadyOnDay;   // cumulative minutes of the work date before this request
        $remaining = $payable;
        foreach ($bands as $band) {
            if ($remaining <= 0) {
                break;
            }
            $room = $band['up_to_minutes'] === null ? $remaining : max(0, $band['up_to_minutes'] - $position);
            $take = min($remaining, $room);
            if ($take <= 0) {
                continue;
            }
            $amount = Money::round(Money::div(Money::mul(Money::mul($hourlyRate, (string) $take), $band['multiplier']), '60'), $decimals); // multiply first, divide last
            $segments[] = ['minutes' => $take, 'multiplier' => Money::trimRate($band['multiplier']), 'amount' => $amount];
            $total = Money::add($total, $amount);
            $position += $take;
            $remaining -= $take;
        }
        if ($remaining > 0) {
            // Bands ended before the minutes did (every band bounded): the excess is not payable.
            $payable -= $remaining;
            $notes[] = 'BEYOND_LAST_BAND';
        }

        return new OvertimeCalculation(
            eligibleMinutes: $eligibleMinutes,
            roundedMinutes: $rounded,
            payableMinutes: $payable,
            dayType: $dayType,
            hourlyRate: $hourlyRate,
            segments: $segments,
            amount: Money::round($total, $decimals),
            notes: $notes,
        );
    }
}
