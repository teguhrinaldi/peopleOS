<?php

namespace App\Services\Overtime;

use Carbon\CarbonInterface;

/**
 * Pure verification maths (no database).
 *
 * verified minutes = the part of the APPROVED window [start_at, start_at + approved minutes] that lies inside the
 * attendance [check-in, check-out] AND outside the scheduled regular shift [expected start, expected end]
 * (regular hours are paid by the salary, not as overtime). On rest days / holidays there is no regular shift,
 * so the whole overlap with check-in/out counts.
 */
final class OvertimeVerifier
{
    public function verifiedMinutes(
        int $approvedMinutes,
        CarbonInterface $startAt,
        CarbonInterface $checkIn,
        CarbonInterface $checkOut,
        ?CarbonInterface $regularStart = null,
        ?CarbonInterface $regularEnd = null,
    ): int {
        $from = $startAt->getTimestamp();
        $to = $from + max(0, $approvedMinutes) * 60;

        $from = max($from, $checkIn->getTimestamp());
        $to = min($to, $checkOut->getTimestamp());
        if ($to <= $from) {
            return 0;
        }
        $seconds = $to - $from;

        if ($regularStart !== null && $regularEnd !== null) {
            $overlap = min($to, $regularEnd->getTimestamp()) - max($from, $regularStart->getTimestamp());
            $seconds -= max(0, $overlap);
        }

        return intdiv(max(0, $seconds), 60);
    }

    /** VERIFIED | PARTIAL | NOT_VERIFIED for a request that has a complete attendance record. */
    public function status(int $approvedMinutes, int $verifiedMinutes): string
    {
        return match (true) {
            $verifiedMinutes >= $approvedMinutes => 'VERIFIED',
            $verifiedMinutes > 0 => 'PARTIAL',
            default => 'NOT_VERIFIED',
        };
    }
}
