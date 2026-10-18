<?php

namespace Tests\Feature\Payroll;

use App\Models\PayrollItem;
use App\Models\PayrollItemLine;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Services\Payroll\PayrollWorkflow;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\PayrollFixtures;
use Tests\Support\WorkplaceFixtures;
use Tests\TestCase;

/**
 * Genuine concurrency: separate PHP processes run payroll actions on the same period at the same instant.
 * Data is committed (no wrapping transaction) and every table is wiped before and after.
 */
class PayrollConcurrencyTest extends TestCase
{
    use PayrollFixtures, WorkplaceFixtures;

    private const EMPLOYEES = 6;

    private PayrollPeriod $payrollPeriod;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is disabled.');
        }
        if (! Schema::hasTable('payroll_runs')) {
            Artisan::call('migrate', ['--force' => true]);
        }

        $this->wipe();
        $this->setUpWorkplace();
        $this->setUpPayroll();
        for ($i = 0; $i < self::EMPLOYEES; $i++) {
            [, $e] = $this->makeStaff();
            $this->giveSalary($e, (string) (5000000 + $i * 250000));
        }
        $this->payrollPeriod = $this->period();
    }

    protected function tearDown(): void
    {
        $this->wipe();
        foreach (glob(storage_path('app/private/payslips/2026/PS-202607-*.pdf')) ?: [] as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function wipe(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (DB::select('SHOW TABLES') as $row) {
            $table = array_values((array) $row)[0];
            if ($table !== 'migrations') {
                DB::table($table)->truncate();
            }
        }
        Schema::enableForeignKeyConstraints();
    }

    /** @return list<array<string, mixed>> */
    private function race(string $action, array $userIds): array
    {
        $startAt = microtime(true) + 5;
        $processes = [];
        foreach ($userIds as $userId) {
            $cmd = [PHP_BINARY, base_path('tests/Support/concurrent_payroll.php'), $action, (string) $this->payrollPeriod->id, (string) $userId, (string) $startAt];
            $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), getenv());
            $processes[] = [$proc, $pipes];
        }

        $results = [];
        foreach ($processes as [$proc, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            proc_close($proc);
            $decoded = json_decode(trim($stdout), true);
            $this->assertIsArray($decoded, "child produced no JSON. stdout={$stdout} stderr={$stderr}");
            $results[] = $decoded;
        }

        return $results;
    }

    public function test_simultaneous_generation_creates_exactly_one_run_and_no_duplicate_items(): void
    {
        [$hr2] = $this->makeStaff('hr');
        $results = $this->race('generate', [$this->hr->id, $hr2->id, $this->hr->id]);

        $ok = array_filter($results, fn ($r) => $r['ok']);
        $this->assertGreaterThanOrEqual(1, count($ok), json_encode($results));
        foreach ($results as $r) {
            $this->assertTrue($r['ok'] || $r['code'] === 'PAYROLL_PROCESSING', 'only PAYROLL_PROCESSING may lose: '.json_encode($results));
        }

        $this->assertSame(1, PayrollRun::query()->count());
        $run = PayrollRun::query()->firstOrFail();
        $this->assertSame('DRAFT', $run->status->value);
        $this->assertNull($run->processing_token);
        $this->assertSame(self::EMPLOYEES, PayrollItem::query()->count());
        $this->assertSame(self::EMPLOYEES, PayrollItem::query()->distinct()->count('employee_id'));
        $this->assertSame(self::EMPLOYEES * 2, PayrollItemLine::query()->count(), 'no orphaned or duplicated lines');
        $this->assertSame(self::EMPLOYEES, $run->employee_count);
    }

    public function test_double_approval_and_double_finalization_succeed_once(): void
    {
        $this->toReview($this->payrollPeriod);
        [$admin2] = $this->makeStaff('admin');

        $approvals = $this->race('approve', [$this->admin->id, $admin2->id]);
        $this->assertCount(1, array_filter($approvals, fn ($r) => $r['ok']), json_encode($approvals));
        $this->assertContains('INVALID_STATE_TRANSITION', array_column($approvals, 'code'));
        $this->assertSame('APPROVED', $this->payrollPeriod->fresh()->status->value);

        $finals = $this->race('finalize', [$this->admin->id, $admin2->id, $this->admin->id]);
        $this->assertCount(1, array_filter($finals, fn ($r) => $r['ok']), json_encode($finals));
        $this->assertSame(['INVALID_STATE_TRANSITION', 'INVALID_STATE_TRANSITION'], array_values(array_column(array_filter($finals, fn ($r) => ! $r['ok']), 'code')));

        $this->assertSame('FINALIZED', $this->payrollPeriod->fresh()->status->value);
        $this->assertSame(self::EMPLOYEES, Payslip::query()->count());
        $this->assertSame(self::EMPLOYEES, Payslip::query()->distinct()->count('payslip_number'));
        $this->assertSame(self::EMPLOYEES, Payslip::query()->whereNotNull('file_path')->count());
        $this->assertSame(1, DB::table('activities')->where('action', 'payroll.finalized')->count());

        // and the (sequential) double click on mark-paid
        app(PayrollWorkflow::class)->markPaid($this->admin, $this->payrollPeriod);
        $this->expectExceptionMessage('cannot mark paid');
        app(PayrollWorkflow::class)->markPaid($this->admin, $this->payrollPeriod);
    }
}
