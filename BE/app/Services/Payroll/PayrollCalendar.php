<?php

namespace App\Services\Payroll;

use App\Models\Holiday;
use App\Models\WorkingCalendar;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;

/**
 * Working days of a payroll period, used as the divisor of UNPAID_LEAVE_DEDUCTION:
 * days of the period that are working days in the COMPANY DEFAULT working calendar (Mon-Fri when none is
 * configured) and are not company holidays. Per-employee calendars are deliberately not used, so every
 * employee's daily rate in a period is basic / the same number (documented in docs/payroll.md).
 */
class PayrollCalendar
{
    public function __construct(private readonly CompanyContext $context) {}

    public function workingDays(string $from, string $to): int
    {
        $calendar = WorkingCalendar::query()
            ->where('company_id', $this->context->id())
            ->where('is_default', true)
            ->with('days')
            ->first();

        $holidays = Holiday::query()
            ->where('company_id', $this->context->id())
            ->whereBetween('date', [$from, $to])
            ->pluck('date')
            ->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())
            ->flip();

        $count = 0;
        $last = CarbonImmutable::parse($to);
        for ($day = CarbonImmutable::parse($from); $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
            if (isset($holidays[$day->toDateString()])) {
                continue;
            }
            $iso = $day->dayOfWeekIso;
            if ($calendar ? $calendar->isWorkingDay($iso) : $iso <= 5) {
                $count++;
            }
        }

        return $count;
    }
}
