<?php

namespace App\Services\Payroll\Contracts;

use App\Models\Employee;
use App\Models\PayrollItemLine;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Services\Payroll\Earnings\EarningContext;
use App\Services\Payroll\Earnings\EarningLine;

/**
 * Extension point: a module that turns its own approved records (overtime, reimbursements, ...) into payroll
 * lines with source references, and guarantees each record is paid exactly once.
 *
 * Register the class in config/payroll.php `earning_sources`. The PayrollGenerator calls, per generation:
 *
 *   OUTSIDE any transaction (read only):
 *     prepare($period, $employeeIds)              batch-load candidates (optional optimisation)
 *     linesFor($employee, $period, $context)      the lines for one employee (pure read; no writes!)
 *   INSIDE the store transaction, period + run already locked FOR UPDATE:
 *     releaseRun($run)                            forget everything this run included before (regeneration)
 *     lockSources($lines)                         lock the referenced records FOR UPDATE and re-validate them;
 *                                                 throw EarningSourceConflict when one changed or was taken
 *     markIncluded($run, $line, $earning)         record that $line (already inserted) pays $earning's records
 *   Workflow hooks (inside the workflow transaction):
 *     freezeRun($run)      at finalize: inclusions become immutable
 *     releaseRun($run)     at cancel / return-to-draft: the records become available again
 *
 * Records must be protected by a UNIQUE database constraint so that "exactly once" survives any bug above.
 */
interface PayrollEarningSource
{
    /** Stable machine key stored on payroll_item_lines.source_key, e.g. "overtime". */
    public function key(): string;

    /** False = the source contributes nothing (feature switched off). */
    public function enabled(): bool;

    /** @param list<int> $employeeIds */
    public function prepare(PayrollPeriod $period, PayrollRun $run, array $employeeIds): void;

    /** @return list<EarningLine> */
    public function linesFor(Employee $employee, PayrollPeriod $period, EarningContext $context): array;

    /** Run-level notes for payroll_runs.summary (e.g. excluded records and why). @return array<string, mixed> */
    public function summary(): array;

    /** @param list<EarningLine> $lines */
    public function lockSources(PayrollRun $run, array $lines): void;

    public function markIncluded(PayrollRun $run, PayrollItemLine $line, EarningLine $earning): void;

    public function releaseRun(PayrollRun $run): int;

    public function freezeRun(PayrollRun $run): void;
}
