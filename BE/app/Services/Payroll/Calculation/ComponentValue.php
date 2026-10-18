<?php

namespace App\Services\Payroll\Calculation;

use App\Enums\SalaryCalculation;
use App\Enums\SalaryComponentKind;

/** One salary component as it applies to one employee on the reference date (catalog + employee value). */
final class ComponentValue
{
    public function __construct(
        public readonly ?int $componentId,
        public readonly string $code,
        public readonly string $name,
        public readonly SalaryComponentKind $kind,
        public readonly SalaryCalculation $calculation,
        public readonly bool $isBasic = false,
        public readonly ?string $amount = null,
        public readonly ?string $rate = null,
        public readonly int $sortOrder = 0,
    ) {}
}
