<?php

namespace App\Enums;

enum GeofenceMode: string
{
    case Enforce = 'enforce';
    case Review = 'review';
    case Off = 'off';
}
