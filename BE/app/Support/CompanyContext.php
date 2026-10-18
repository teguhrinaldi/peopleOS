<?php

namespace App\Support;

use App\Models\Company;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * One installation = one company. This is the single place that knows the company,
 * its timezone and "now". Bound as a singleton, so it resets with every app instance.
 *
 * All datetimes are stored in UTC; anything the user reads is converted with this timezone.
 */
class CompanyContext
{
    private ?Company $company = null;

    private ?string $timezone = null;

    public function company(): Company
    {
        return $this->company ??= Company::query()->orderBy('id')->firstOrFail();
    }

    public function id(): int
    {
        return $this->company()->id;
    }

    public function timezone(): string
    {
        return $this->timezone ??= (
            ($this->company ??= Company::query()->orderBy('id')->first())?->timezone
            ?: config('peopleos.default_timezone', 'Asia/Jakarta')
        );
    }

    /** Authoritative server clock, expressed in the company timezone. */
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::instance(now())->setTimezone($this->timezone());
    }

    public function today(): CarbonImmutable
    {
        return $this->now()->startOfDay();
    }

    public function toLocal(CarbonInterface|string $moment): CarbonImmutable
    {
        return CarbonImmutable::parse($moment)->setTimezone($this->timezone());
    }

    /** Wall-clock date + time in the company timezone -> UTC instant. */
    public function localToUtc(CarbonInterface|string $date, string $time): CarbonImmutable
    {
        $day = $date instanceof CarbonInterface ? $date->format('Y-m-d') : substr($date, 0, 10);

        return CarbonImmutable::parse("{$day} {$time}", $this->timezone())->utc();
    }

    public function forget(): void
    {
        $this->company = null;
        $this->timezone = null;
    }
}
