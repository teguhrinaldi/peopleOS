<?php

namespace App\Services\Leave;

use App\Enums\DayType;
use App\Models\Employee;
use App\Services\Attendance\ScheduleService;
use App\Support\CompanyContext;
use App\Support\Settings;
use Carbon\CarbonImmutable;

/**
 * Which days a leave request actually consumes, and which leave year a date falls in.
 * Only the employee's working days count (their calendar, holidays): a Friday-to-Monday request is 2 days.
 */
class LeaveCalendar
{
    public function __construct(
        private readonly ScheduleService $schedules,
        private readonly CompanyContext $context,
        private readonly Settings $settings,
    ) {}

    /**
     * @return list<array{date: string, slots: list<string>}> working days in range with the half-day slots used
     */
    public function slots(Employee $employee, string $start, string $end, ?string $halfDay = null): array
    {
        $out = [];
        $tz = $this->context->timezone();
        $last = CarbonImmutable::parse($end, $tz);

        for ($day = CarbonImmutable::parse($start, $tz); $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
            $date = $day->toDateString();
            if ($this->schedules->peek($employee, $date)->day_type !== DayType::Workday) {
                continue;
            }
            $out[] = ['date' => $date, 'slots' => $halfDay ? [$halfDay] : ['AM', 'PM']];
        }

        return $out;
    }

    /** @param list<array{date: string, slots: list<string>}> $slots */
    public function countDays(array $slots): string
    {
        $halves = array_sum(array_map(fn ($d) => count($d['slots']), $slots));

        return bcdiv((string) $halves, '2', 1);
    }

    /** Leave years are labelled by the calendar year they start in (Settings: leave.year_start_month). */
    public function yearOf(string $date): int
    {
        $d = CarbonImmutable::parse($date);
        $startMonth = $this->startMonth();

        return $d->month >= $startMonth ? $d->year : $d->year - 1;
    }

    /** @return array{0: string, 1: string} first and last date of a leave year */
    public function yearBounds(int $year): array
    {
        $start = CarbonImmutable::create($year, $this->startMonth(), 1);

        return [$start->toDateString(), $start->addYear()->subDay()->toDateString()];
    }

    private function startMonth(): int
    {
        return min(12, max(1, $this->settings->int('leave.year_start_month')));
    }
}
