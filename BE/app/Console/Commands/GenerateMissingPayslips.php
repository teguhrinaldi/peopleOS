<?php

namespace App\Console\Commands;

use App\Enums\PayrollStatus;
use App\Models\Payslip;
use App\Services\Payroll\PayslipDocuments;
use Illuminate\Console\Command;
use Throwable;

/**
 * Job-safe repair: renders payslip PDFs that were never written (file_path NULL) and, with --verify, those whose
 * recorded file disappeared from the disk. Each slip is locked while handled, existing files are never rewritten,
 * and figures come from the frozen snapshot only.
 */
class GenerateMissingPayslips extends Command
{
    protected $signature = 'payroll:payslips {--verify : Also re-create files that are recorded but missing on disk}';

    protected $description = 'Generate missing payslip PDFs for finalized payroll (idempotent)';

    public function handle(PayslipDocuments $documents): int
    {
        $written = 0;
        $failed = 0;

        Payslip::query()
            ->whereIn('payroll_item_id', fn ($q) => $q->select('payroll_items.id')->from('payroll_items')
                ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_items.payroll_run_id')
                ->whereIn('payroll_runs.status', [PayrollStatus::Finalized->value, PayrollStatus::Paid->value]))
            ->when(! $this->option('verify'), fn ($q) => $q->whereNull('file_path'))
            ->orderBy('id')
            ->each(function (Payslip $slip) use ($documents, &$written, &$failed) {
                try {
                    $written += $documents->ensure($slip) ? 1 : 0;
                } catch (Throwable $e) {
                    $failed++;
                    $this->warn("Payslip {$slip->payslip_number}: {$e->getMessage()}");
                }
            });

        $this->info("Payslip PDFs written: {$written}, failed: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
