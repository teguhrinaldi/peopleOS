<?php

namespace App\Services\Admin;

use App\Models\Holiday;
use App\Models\WorkingCalendar;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;

/**
 * Company-wide "is this a working day?" for summaries: holidays and the default calendar only.
 * Per-employee calendars and shift overrides are resolved by ScheduleService, not here.
 */
class CompanyCalendar
{
    public function __construct(private readonly CompanyContext $context) {}

    /**
     * Working-day flags for a whole range with two queries (holidays + default calendar), same rules as isWorkingDay().
     *
     * @return array<string, bool> keyed by Y-m-d
     */
    public function workingDays(string $from, string $to): array
    {
        $holidays = Holiday::query()->where('company_id', $this->context->id())->whereBetween('date', [$from, $to])->pluck('date')
            ->map(fn ($d) => $d->toDateString())->flip();
        $calendar = WorkingCalendar::query()->where('company_id', $this->context->id())->where('is_default', true)->with('days')->first();

        $out = [];
        for ($day = CarbonImmutable::parse($from); $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $date = $day->toDateString();
            $out[$date] = ! $holidays->has($date) && ($calendar ? $calendar->isWorkingDay($day->dayOfWeekIso) : $day->dayOfWeekIso <= 5);
        }

        return $out;
    }

    public function isWorkingDay(string $date): bool
    {
        if (Holiday::query()->where('company_id', $this->context->id())->whereDate('date', $date)->exists()) {
            return false;
        }

        $isoDay = CarbonImmutable::parse($date)->dayOfWeekIso;
        $calendar = WorkingCalendar::query()->where('company_id', $this->context->id())->where('is_default', true)->with('days')->first();

        return $calendar ? $calendar->isWorkingDay($isoDay) : $isoDay <= 5;
    }
}
