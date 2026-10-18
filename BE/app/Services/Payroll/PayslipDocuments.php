<?php

namespace App\Services\Payroll;

use App\Enums\PayrollStatus;
use App\Enums\SalaryComponentKind;
use App\Exceptions\ApiException;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Services\Notifications\NotificationService;
use App\Support\CompanyContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Payslip PDFs. Rendered ONLY from the frozen snapshot (run + item + lines), never from live salary data or
 * current settings, so a re-render after a salary change produces the same figures.
 *
 * ensure() is job-safe and idempotent: it locks the payslip row, does nothing when the recorded file exists,
 * and (re)creates it only when it is missing. The employee is notified once (dedupe key per payslip).
 */
class PayslipDocuments
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly CompanyContext $context,
    ) {}

    /** Render every missing PDF of a finalized run. Failures are logged and left for `payroll:payslips`. */
    public function generateForRun(PayrollRun $run): int
    {
        $done = 0;
        Payslip::query()
            ->whereIn('payroll_item_id', fn ($q) => $q->select('id')->from('payroll_items')->where('payroll_run_id', $run->id))
            ->whereNull('file_path')
            ->orderBy('id')
            ->each(function (Payslip $slip) use (&$done) {
                try {
                    $this->ensure($slip);
                    $done++;
                } catch (Throwable $e) {
                    Log::warning('Payslip PDF generation failed', ['payslip_id' => $slip->id, 'error' => $e->getMessage()]);
                }
            });

        return $done;
    }

    /** @return bool true when a file was written */
    public function ensure(Payslip $payslip, bool $verifyDisk = true): bool
    {
        return DB::transaction(function () use ($payslip, $verifyDisk) {
            $slip = Payslip::query()->whereKey($payslip->id)->lockForUpdate()->firstOrFail();
            $disk = $this->disk();

            if ($slip->file_path !== null && (! $verifyDisk || $disk->exists($slip->file_path))) {
                $this->notifyOnce($slip);

                return false;
            }

            $status = DB::table('payroll_runs')->where('id', $slip->item()->value('payroll_run_id'))->value('status');
            if (! PayrollStatus::from((string) $status)->isReleased()) {
                throw new \LogicException('Payslips are rendered for finalized payroll only.');
            }

            $pdf = $this->render($slip);
            $path = $slip->file_path ?? sprintf(
                '%s/%s/%s-%s.pdf',
                trim((string) config('payroll.payslip_directory', 'payslips'), '/'),
                $slip->issued_at->format('Y'),
                $slip->payslip_number,
                Str::lower(Str::random(10)),
            );
            $disk->put($path, $pdf);

            $slip->repairingMissingFile = $slip->file_path !== null;
            $slip->fill([
                'file_path' => $path,
                'file_size' => strlen($pdf),
                'file_sha256' => hash('sha256', $pdf),
                'file_generated_at' => now(),
            ])->save();
            $slip->repairingMissingFile = false;

            $this->notifyOnce($slip);

            return true;
        });
    }

    /** Stream the PDF (re-rendering it first if the file went missing). Callers authorize beforehand. */
    public function stream(Payslip $payslip, bool $download = false): StreamedResponse
    {
        $this->ensure($payslip);
        $payslip->refresh();

        $disk = $this->disk();
        if (! $payslip->file_path || ! $disk->exists($payslip->file_path)) {
            throw ApiException::make('NOT_FOUND', 'The payslip document is not available.', 404);
        }

        return $disk->response($payslip->file_path, $payslip->payslip_number.'.pdf', [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], $download ? 'attachment' : 'inline');
    }

    /** PDF bytes for a payslip, from its snapshot only. */
    public function render(Payslip $payslip): string
    {
        return Pdf::loadHTML($this->html($payslip))
            ->setPaper('a4')
            ->setOption(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false, 'isPhpEnabled' => false])
            ->output();
    }

    /** The payslip document as HTML (what the PDF is rendered from). */
    public function html(Payslip $payslip): string
    {
        $payslip->loadMissing(['item.lines', 'item.run.period']);
        $item = $payslip->item;
        $run = $item->run;
        $period = $run->period;
        $d = (int) $run->decimals;
        $money = fn ($v) => Money::format((string) $v, $d);

        return view('payroll.payslip', [
            'payslip' => $payslip,
            'item' => $item,
            'run' => $run,
            'period' => $period,
            'company' => $run->company_snapshot ?? ['name' => $this->context->company()->name],
            'currency' => $run->currency,
            'earnings' => $item->lines->where('kind', SalaryComponentKind::Earning)->values(),
            'deductions' => $item->lines->where('kind', SalaryComponentKind::Deduction)->values(),
            'money' => $money,
            'money2' => fn ($v) => Money::format((string) $v, 2),
            'issuedAt' => $this->context->toLocal($payslip->issued_at),
        ])->render();
    }

    private function notifyOnce(Payslip $slip): void
    {
        if ($slip->notified_at !== null) {
            return;
        }
        $slip->loadMissing(['employee.user', 'period']);
        $this->notifications->notify(
            $slip->employee,
            'payslip.available',
            'Your payslip is available',
            "Payslip {$slip->payslip_number} for {$slip->period->name} is ready to view.",
            $slip,
            ['payslip_id' => $slip->id, 'payroll_period_id' => $slip->payroll_period_id],
            'payslip.available.'.$slip->id,
        );
        $slip->fill(['notified_at' => now()])->save();
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('payroll.payslip_disk', 'local'));
    }
}
