<?php

namespace App\Services\Payroll\Calculation;

use App\Enums\SalaryComponentKind;

/** A manual adjustment line (ADJUSTMENT periods). Taken as-is, only rounded. */
final class ManualEntry
{
    public function __construct(
        public readonly SalaryComponentKind $kind,
        public readonly string $label,
        public readonly string $amount,
        public readonly string $reason,
        public readonly ?int $adjustmentId = null,
    ) {}
}
