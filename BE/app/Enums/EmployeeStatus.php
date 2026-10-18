<?php

namespace App\Enums;

enum EmployeeStatus: string
{
    case Pending = 'PENDING';
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
    case Terminated = 'TERMINATED';
}
