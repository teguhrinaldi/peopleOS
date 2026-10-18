<?php

namespace App\Enums;

enum DayType: string
{
    case Workday = 'workday';
    case RestDay = 'rest_day';
    case Holiday = 'holiday';
}
