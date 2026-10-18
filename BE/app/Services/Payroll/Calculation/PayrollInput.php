<?php

namespace App\Services\Payroll\Calculation;

use App\Services\Payroll\Earnings\EarningLine;

/**
 * Everything the calculator needs for one employee and one period. Built by PayrollInputCollector from the
 * database; built by hand in unit tests. No models, no database, no clock.
 */
final class PayrollInput
{
    /**
     * @param  list<ComponentValue>  $components
     * @param  list<ManualEntry>  $manualEntries
     * @param  list<EarningLine>  $sourceLines  lines from registered earning sources (overtime engine, ...)
     */
    public function __construct(
        public readonly array $components,
        public readonly int $decimals = 0,
        public readonly int $workingDays = 0,
        public readonly int $presentDays = 0,
        public readonly int $overtimeMinutes = 0,
        public readonly string $unpaidLeaveDays = '0',
        public readonly array $manualEntries = [],
        public readonly array $sourceLines = [],
        // true when the overtime rule engine is on: PER_OVERTIME_HOUR components are skipped (no double count)
        public readonly bool $overtimeComponentSuperseded = false,
    ) {}
}
