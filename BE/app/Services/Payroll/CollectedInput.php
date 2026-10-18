<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Services\Payroll\Calculation\PayrollInput;

final class CollectedInput
{
    /** @param array<string, mixed> $snapshot */
    public function __construct(
        public readonly Employee $employee,
        public readonly string $referenceDate,
        public readonly array $snapshot,
        public readonly PayrollInput $input,
    ) {}
}
