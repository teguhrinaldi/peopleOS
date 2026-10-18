<?php

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;

/** Everything the device reports about one check-in / check-out attempt. All of it is untrusted input. */
final class AttendanceCapture
{
    /** @param array<string, mixed>|null $deviceInfo */
    public function __construct(
        public readonly string $idempotencyKey,
        public readonly ?float $latitude = null,
        public readonly ?float $longitude = null,
        public readonly ?float $accuracyMeters = null,
        public readonly ?CarbonImmutable $locationCapturedAt = null,
        public readonly ?CarbonImmutable $deviceCapturedAt = null,
        public readonly bool $isOfflineSync = false,
        public readonly bool $isMockLocation = false,
        public readonly ?array $deviceInfo = null,
    ) {}
}
