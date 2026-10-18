<?php

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Pure shift maths. No database access, so every rule here is unit-testable.
 *
 * A shift is one logical span that may cross midnight (22:00 -> 06:00). It belongs to the
 * work date it *starts* on, and its end is computed by rolling forward one day when needed,
 * never by comparing wall-clock times on the same calendar day.
 */
class ShiftCalculator
{
    /** A shift crosses midnight when its end wall-clock time is not after its start time. */
    public function crossesMidnight(string $startTime, string $endTime): bool
    {
        return $this->seconds($endTime) <= $this->seconds($startTime);
    }

    /** Gross length in minutes from start to end, across midnight if needed. */
    public function spanMinutes(string $startTime, string $endTime): int
    {
        $span = $this->seconds($endTime) - $this->seconds($startTime);
        if ($span <= 0) {
            $span += 86400;
        }

        return intdiv($span, 60);
    }

    /**
     * Net paid working minutes: span minus unpaid breaks.
     *
     * @param  iterable<array{duration_minutes: int, is_paid?: bool}>  $breaks
     */
    public function workingMinutes(string $startTime, string $endTime, iterable $breaks = []): int
    {
        $unpaid = 0;
        foreach ($breaks as $break) {
            if (! ($break['is_paid'] ?? false)) {
                $unpaid += (int) $break['duration_minutes'];
            }
        }

        return max(0, $this->spanMinutes($startTime, $endTime) - $unpaid);
    }

    /**
     * Expected start/end instants (UTC) of a shift that starts on $workDate in $timezone.
     * DST-safe: wall-clock times are resolved in the timezone, then converted.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function window(string $workDate, string $startTime, string $endTime, string $timezone): array
    {
        $day = substr($workDate, 0, 10);
        $start = CarbonImmutable::parse("{$day} {$startTime}", $timezone);
        $end = CarbonImmutable::parse("{$day} {$endTime}", $timezone);

        if ($this->crossesMidnight($startTime, $endTime)) {
            $end = CarbonImmutable::parse("{$day} {$endTime}", $timezone)->addDay();
        }

        return [$start->utc(), $end->utc()];
    }

    /**
     * Minutes late. Zero while inside the grace period; once grace is exceeded the whole delay
     * counted from the scheduled start is late time.
     */
    public function lateMinutes(CarbonInterface $expectedStart, CarbonInterface $checkIn, int $graceMinutes): int
    {
        $delay = $this->minutesBetween($expectedStart, $checkIn);

        return $delay > $graceMinutes ? $delay : 0;
    }

    /** Minutes left early. Zero when within the early-leave grace. */
    public function earlyLeaveMinutes(CarbonInterface $expectedEnd, CarbonInterface $checkOut, int $graceMinutes): int
    {
        $early = $this->minutesBetween($checkOut, $expectedEnd);

        return $early > $graceMinutes ? $early : 0;
    }

    /** Minutes on the clock between check-in and check-out minus unpaid break time, never negative. */
    public function workedMinutes(CarbonInterface $checkIn, CarbonInterface $checkOut, int $breakMinutes = 0): int
    {
        $span = max(0, $this->minutesBetween($checkIn, $checkOut));

        return $span > $breakMinutes ? $span - $breakMinutes : $span;
    }

    /** Whole minutes from $from to $to; negative when $to is earlier. */
    public function minutesBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        return intdiv($to->getTimestamp() - $from->getTimestamp(), 60);
    }

    private function seconds(string $time): int
    {
        $parts = array_map('intval', explode(':', $time) + [0, 0, 0]);

        return $parts[0] * 3600 + $parts[1] * 60 + ($parts[2] ?? 0);
    }
}
