<?php

namespace App\Services\Overtime;

use App\Models\OvertimeRule;
use App\Services\Payroll\Money;
use InvalidArgumentException;

/**
 * The calculation-relevant part of an overtime rule as a plain value (no database). Built from an OvertimeRule
 * row, or by hand in unit tests. toSnapshot() is what gets frozen on every inclusion.
 */
final class OvertimeRuleConfig
{
    public const DAY_TYPES = ['workday', 'rest_day', 'holiday'];

    public const BASES = ['BASIC', 'FIXED_EARNINGS'];

    public const DIRECTIONS = ['DOWN', 'NEAREST', 'UP'];

    /**
     * @param  array<string, list<array{up_to_minutes: ?int, multiplier: string}>>  $tiers  day type => ordered bands
     */
    public function __construct(
        public readonly array $tiers,
        public readonly string $rateBasis = 'BASIC',
        public readonly string $rateDivisor = '173',
        public readonly int $roundingMinutes = 1,
        public readonly string $roundingDirection = 'DOWN',
        public readonly int $minMinutesPerRequest = 0,
        public readonly ?int $maxMinutesPerDay = null,
        public readonly ?int $maxMinutesPerPeriod = null,
        public readonly ?int $id = null,
        public readonly string $name = 'Overtime rule',
    ) {
        if (Money::cmp($rateDivisor, '0') <= 0) {
            throw new InvalidArgumentException('rate_divisor must be greater than 0.');
        }
        if ($roundingMinutes < 1) {
            throw new InvalidArgumentException('rounding_minutes must be at least 1.');
        }
        if (! in_array($roundingDirection, self::DIRECTIONS, true) || ! in_array($rateBasis, self::BASES, true)) {
            throw new InvalidArgumentException('Unknown rounding direction or rate basis.');
        }
    }

    /**
     * Normalise the stored/API tier JSON ({day: [{up_to_hours?, multiplier}]}) into minute bands.
     *
     * @param  array<string, mixed>  $json
     * @return array<string, list<array{up_to_minutes: ?int, multiplier: string}>>
     */
    public static function tiersFromJson(array $json): array
    {
        $out = [];
        foreach ($json as $day => $bands) {
            if (! in_array($day, self::DAY_TYPES, true) || ! is_array($bands)) {
                throw new InvalidArgumentException("Unknown day type [{$day}] in multiplier tiers.");
            }
            $previous = 0;
            $list = [];
            foreach (array_values($bands) as $i => $band) {
                $upTo = isset($band['up_to_hours']) && $band['up_to_hours'] !== null
                    ? (int) Money::round(Money::mul((string) $band['up_to_hours'], '60'), 0) : null;
                $multiplier = Money::of((string) ($band['multiplier'] ?? ''));
                if (Money::cmp($multiplier, '0') <= 0) {
                    throw new InvalidArgumentException('Every band needs a multiplier greater than 0.');
                }
                if ($upTo !== null && $upTo <= $previous) {
                    throw new InvalidArgumentException('Band limits (up_to_hours) must increase.');
                }
                if ($upTo === null && $i !== count($bands) - 1) {
                    throw new InvalidArgumentException('Only the last band may be open-ended.');
                }
                $list[] = ['up_to_minutes' => $upTo, 'multiplier' => $multiplier];
                $previous = $upTo ?? $previous;
            }
            $out[$day] = $list;
        }

        return $out;
    }

    public static function fromModel(OvertimeRule $rule): self
    {
        return new self(
            tiers: self::tiersFromJson((array) $rule->multiplier_tiers),
            rateBasis: $rule->rate_basis,
            rateDivisor: (string) $rule->rate_divisor,
            roundingMinutes: (int) $rule->rounding_minutes,
            roundingDirection: $rule->rounding_direction,
            minMinutesPerRequest: (int) $rule->min_minutes_per_request,
            maxMinutesPerDay: $rule->max_minutes_per_day,
            maxMinutesPerPeriod: $rule->max_minutes_per_period,
            id: $rule->id,
            name: $rule->name,
        );
    }

    /** @return array<string, mixed> */
    public function toSnapshot(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'rate_basis' => $this->rateBasis,
            'rate_divisor' => $this->rateDivisor,
            'tiers' => $this->tiers,
            'rounding_minutes' => $this->roundingMinutes,
            'rounding_direction' => $this->roundingDirection,
            'min_minutes_per_request' => $this->minMinutesPerRequest,
            'max_minutes_per_day' => $this->maxMinutesPerDay,
            'max_minutes_per_period' => $this->maxMinutesPerPeriod,
        ];
    }
}
