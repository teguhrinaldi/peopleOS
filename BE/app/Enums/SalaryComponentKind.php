<?php

namespace App\Enums;

enum SalaryComponentKind: string
{
    case Earning = 'EARNING';
    case Deduction = 'DEDUCTION';
}
