<?php

namespace App\Services\Payroll\Calculation;

use App\Enums\SalaryComponentKind;
use App\Services\Payroll\Money;

final class PayrollResult
{
    /**
     * @param  list<CalculatedLine>  $lines
     * @param  list<string>  $warnings  machine codes, e.g. NEGATIVE_NET, NO_WORKING_DAYS
     */
    public function __construct(
        public readonly array $lines,
        public readonly string $basic,
        public readonly string $gross,
        public readonly string $deductions,
        public readonly string $net,
        public readonly array $warnings = [],
    ) {}

    public function isNegative(): bool
    {
        return Money::isNegative($this->net);
    }

    /** @return list<CalculatedLine> */
    public function earnings(): array
    {
        return array_values(array_filter($this->lines, fn (CalculatedLine $l) => $l->kind === SalaryComponentKind::Earning));
    }

    /** @return list<CalculatedLine> */
    public function deductionLines(): array
    {
        return array_values(array_filter($this->lines, fn (CalculatedLine $l) => $l->kind === SalaryComponentKind::Deduction));
    }
}
