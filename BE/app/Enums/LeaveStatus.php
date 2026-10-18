<?php

namespace App\Enums;

enum LeaveStatus: string
{
    case Draft = 'DRAFT';
    case Pending = 'PENDING';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case Cancelled = 'CANCELLED';

    /** Requests that hold days on the calendar and on the balance. */
    public function isLive(): bool
    {
        return $this === self::Pending || $this === self::Approved;
    }
}
