<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\AttendanceEvent;

final class AttendanceResult
{
    public function __construct(
        public readonly Attendance $attendance,
        public readonly AttendanceEvent $event,
        /** True when the idempotency key was seen before and the stored outcome was returned unchanged. */
        public readonly bool $replayed = false,
    ) {}
}
