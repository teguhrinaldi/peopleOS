<?php

namespace App\Enums;

enum AttendanceEventType: string
{
    case CheckIn = 'CHECK_IN';
    case CheckOut = 'CHECK_OUT';
}
