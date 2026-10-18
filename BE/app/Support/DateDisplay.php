<?php

namespace App\Support;

use Carbon\CarbonInterface;

/** Dates shown in the admin follow Settings `general.date_format` (default "d M Y"). */
final class DateDisplay
{
    /** Formats offered in Settings: value => example label. */
    public const FORMATS = [
        'd M Y' => '30 Sep 2026',
        'd/m/Y' => '30/09/2026',
        'm/d/Y' => '09/30/2026',
        'Y-m-d' => '2026-09-30',
        'd.m.Y' => '30.09.2026',
    ];

    private static ?string $format = null;

    public static function date(?CarbonInterface $date): string
    {
        return $date ? $date->format(self::format()) : '—';
    }

    /** A stored UTC instant as company-local "date, HH:mm". */
    public static function moment(?CarbonInterface $moment): string
    {
        return $moment ? app(CompanyContext::class)->toLocal($moment)->format(self::format().', H:i') : '—';
    }

    /** A start–end range, collapsing to one date when they match. */
    public static function range(CarbonInterface $start, CarbonInterface $end): string
    {
        return $start->isSameDay($end) ? self::date($start) : self::date($start).' – '.self::date($end);
    }

    public static function format(): string
    {
        $format = self::$format ??= app(Settings::class)->string('general.date_format');

        return array_key_exists($format, self::FORMATS) ? $format : 'd M Y';
    }

    /** Settings changed in this request (or a new test): read the value again. */
    public static function forget(): void
    {
        self::$format = null;
    }
}
