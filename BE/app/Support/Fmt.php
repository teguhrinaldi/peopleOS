<?php

namespace App\Support;

use Carbon\CarbonInterface;

/** Formats stored UTC instants for API/UI in the company timezone. */
class Fmt
{
    /** ISO-8601 with the company UTC offset, e.g. 2026-09-30T08:42:00+07:00 */
    public static function iso(?CarbonInterface $moment): ?string
    {
        return $moment ? app(CompanyContext::class)->toLocal($moment)->toIso8601String() : null;
    }

    /** "HH:mm" in company time. */
    public static function time(?CarbonInterface $moment): ?string
    {
        return $moment ? app(CompanyContext::class)->toLocal($moment)->format('H:i') : null;
    }

    public static function date(?CarbonInterface $moment): ?string
    {
        return $moment?->format('Y-m-d');
    }

    /** "HH:MM:SS" DB time column -> "HH:MM". */
    public static function hm(?string $time): ?string
    {
        return $time ? substr($time, 0, 5) : null;
    }
}
