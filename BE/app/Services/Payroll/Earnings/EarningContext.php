<?php

namespace App\Services\Payroll\Earnings;

use App\Enums\SalaryCalculation;
use App\Enums\SalaryComponentKind;
use App\Models\PayrollRun;
use App\Services\Payroll\Calculation\ComponentValue;
use App\Services\Payroll\Money;

/** What an earning source may know about the employee's payroll item being built (read-only, no DB). */
final class EarningContext
{
    /** @param list<ComponentValue> $components the employee's salary values on the reference date */
    public function __construct(
        public readonly PayrollRun $run,
        public readonly array $components,
        public readonly int $decimals,
        public readonly string $referenceDate,
    ) {}

    /** Sum of FIXED earnings flagged is_basic. */
    public function basic(): string
    {
        return $this->fixedEarnings(true);
    }

    /** Sum of FIXED earnings (basic only, or every fixed earning). */
    public function fixedEarnings(bool $basicOnly = false): string
    {
        $sum = '0';
        foreach ($this->components as $c) {
            if ($c->kind === SalaryComponentKind::Earning && $c->calculation === SalaryCalculation::Fixed && (! $basicOnly || $c->isBasic)) {
                $sum = Money::add($sum, Money::round($c->amount ?? '0', $this->decimals));
            }
        }

        return Money::round($sum, 2);
    }
}
