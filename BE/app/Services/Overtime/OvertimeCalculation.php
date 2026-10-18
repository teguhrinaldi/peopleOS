<?php

namespace App\Services\Overtime;

/** Result of OvertimeCalculator::calculate() for one request. */
final class OvertimeCalculation
{
    /**
     * @param  list<array{minutes: int, multiplier: string, amount: string}>  $segments
     * @param  list<string>  $notes  ROUNDED, BELOW_MINIMUM, CAPPED_DAY, CAPPED_PERIOD, NO_TIER_FOR_DAY_TYPE, BEYOND_LAST_BAND
     */
    public function __construct(
        public readonly int $eligibleMinutes,
        public readonly int $roundedMinutes,
        public readonly int $payableMinutes,
        public readonly string $dayType,
        public readonly string $hourlyRate,
        public readonly array $segments,
        public readonly string $amount,
        public readonly array $notes = [],
    ) {}

    /** Effective multiplier when a single band applied, otherwise null (see segments). */
    public function multiplier(): ?string
    {
        return count($this->segments) === 1 ? $this->segments[0]['multiplier'] : null;
    }
}
