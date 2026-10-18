<?php

namespace App\Enums;

enum AttendanceStatus: string
{
    case Pending = 'PENDING';
    case CheckedIn = 'CHECKED_IN';
    case CheckedOut = 'CHECKED_OUT';
    case CorrectionRequested = 'CORRECTION_REQUESTED';
    case Corrected = 'CORRECTED';
    case Rejected = 'REJECTED';
}
