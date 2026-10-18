<?php

namespace App\Enums;

/**
 * How a salary component's amount is derived. Generic building blocks only: no tax, social security or
 * statutory rule is encoded here. Buyers combine these for their own jurisdiction.
 */
enum SalaryCalculation: string
{
    case Fixed = 'FIXED';                                 // amount per period
    case PercentOfBasic = 'PERCENT_OF_BASIC';             // rate % x basic salary
    case PercentOfGross = 'PERCENT_OF_GROSS';             // rate % x gross pay (deductions only)
    case PerPresentDay = 'PER_PRESENT_DAY';               // amount x present days
    case PerOvertimeHour = 'PER_OVERTIME_HOUR';           // amount x approved overtime hours
    case UnpaidLeaveDeduction = 'UNPAID_LEAVE_DEDUCTION'; // basic / working days x unpaid leave days (deductions only)

    /** Stored on lines produced from manual adjustment entries. Not selectable for components. */
    public const MANUAL_ADJUSTMENT = 'MANUAL_ADJUSTMENT';

    public function usesAmount(): bool
    {
        return in_array($this, [self::Fixed, self::PerPresentDay, self::PerOvertimeHour], true);
    }

    public function usesRate(): bool
    {
        return in_array($this, [self::PercentOfBasic, self::PercentOfGross, self::UnpaidLeaveDeduction], true);
    }

    public function deductionOnly(): bool
    {
        return $this === self::PercentOfGross || $this === self::UnpaidLeaveDeduction;
    }
}
