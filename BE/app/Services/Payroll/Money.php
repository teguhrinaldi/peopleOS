<?php

namespace App\Services\Payroll;

/**
 * bcmath money helpers. Amounts are decimal strings end to end; floats never touch a salary.
 * Rounding is half-up (away from zero) to the currency's decimals.
 */
final class Money
{
    public const SCALE = 10;

    public static function of(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }
        if (is_float($value)) {
            $value = number_format($value, self::SCALE, '.', '');
        }
        $value = trim((string) $value);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            throw new \InvalidArgumentException("Not a decimal number: [{$value}]");
        }

        return $value;
    }

    public static function add(string ...$values): string
    {
        $sum = '0';
        foreach ($values as $v) {
            $sum = bcadd($sum, self::of($v), self::SCALE);
        }

        return $sum;
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub(self::of($a), self::of($b), self::SCALE);
    }

    public static function mul(string $a, string $b): string
    {
        return bcmul(self::of($a), self::of($b), self::SCALE);
    }

    public static function div(string $a, string $b): string
    {
        return bccomp(self::of($b), '0', self::SCALE) === 0 ? '0' : bcdiv(self::of($a), self::of($b), self::SCALE);
    }

    /** Half-up (away from zero) rounding, returned with exactly $decimals places. */
    public static function round(string $value, int $decimals): string
    {
        $value = self::of($value);
        $half = $decimals > 0 ? '0.'.str_repeat('0', $decimals).'5' : '0.5';
        $rounded = str_starts_with($value, '-')
            ? bcsub($value, $half, $decimals)
            : bcadd($value, $half, $decimals);

        return self::isZero($rounded) ? bcadd('0', '0', $decimals) : $rounded;
    }

    public static function cmp(string $a, string $b): int
    {
        return bccomp(self::of($a), self::of($b), self::SCALE);
    }

    public static function isZero(string $value): bool
    {
        return self::cmp($value, '0') === 0;
    }

    public static function isNegative(string $value): bool
    {
        return self::cmp($value, '0') < 0;
    }

    /** Display only: 8000000.00 -> "8,000,000" (0 decimals) or "8,000,000.00". Pure string maths. */
    public static function format(string $value, int $decimals): string
    {
        $value = self::round($value, $decimals);
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        [$int, $frac] = array_pad(explode('.', $value, 2), 2, '');
        $int = strrev(implode(',', str_split(strrev($int), 3)));

        return ($negative ? '-' : '').$int.($decimals > 0 ? '.'.$frac : '');
    }

    /** Trim a stored rate like "5.0000" to "5" or "2.5" for descriptions. */
    public static function trimRate(string $rate): string
    {
        $rate = self::of($rate);

        return str_contains($rate, '.') ? rtrim(rtrim($rate, '0'), '.') : $rate;
    }
}
