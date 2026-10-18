<?php

namespace Tests\Feature\Payroll;

use App\Models\Employee;
use App\Models\OvertimePayrollInclusion;
use App\Models\OvertimeRequest;
use App\Models\PayrollItem;
use App\Models\PayrollItemLine;
use App\Models\PayrollPeriod;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\OvertimeFixtures;
use Tests\Support\PayrollFixtures;
use Tests\Support\WorkplaceFixtures;
use Tests\TestCase;

/**
 * Real parallel PHP processes generate payroll while approved overtime is waiting: every request must end up
 * in exactly one live inclusion and one run. Committed data; all tables wiped before and after.
 */
class OvertimeConcurrencyTest extends TestCase
{
    use OvertimeFixtures, PayrollFixtures, WorkplaceFixtures;

    /** @var list<Employee> */
    private array $staff = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is disabled.');
        }
        if (! Schema::hasTable('overtime_payroll_inclusions')) {
            Artisan::call('migrate', ['--force' => true]);
        }
        $this->wipe();
        $this->setUpWorkplace();
        $this->setUpPayroll();
        $this->setUpOvertimeEngine();
        for ($i = 0; $i < 4; $i++) {
            [, $e] = $this->makeStaff();
            $this->giveSalary($e, '5190000', '2026-01-01', []);
            $this->staff[] = $e;
        }
    }

    protected function tearDown(): void
    {
        $this->wipe();
        foreach (glob(storage_path('app/private/payslips/2026/PS-2026*.pdf')) ?: [] as $file) {
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

    /**
     * @param  list<array{0: int, 1: int}>  $jobs  [periodId, userId]
     * @return list<array<string, mixed>>
     */
    private function race(array $jobs): array
    {
        $startAt = microtime(true) + 5;
        $processes = [];
        foreach ($jobs as [$periodId, $userId]) {
            $cmd = [PHP_BINARY, base_path('tests/Support/concurrent_payroll.php'), 'generate', (string) $periodId, (string) $userId, (string) $startAt];
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

    private function overtimeLinesOf(PayrollPeriod $period): int
    {
        return PayrollItemLine::query()->where('source_key', 'overtime')
            ->whereIn('payroll_item_id', PayrollItem::query()->select('id')->where('payroll_run_id', $period->run->id))->count();
    }

    public function test_simultaneous_generation_of_one_period_includes_each_overtime_request_once(): void
    {
        foreach ($this->staff as $e) {
            $this->worked($e, '2026-07-14', '20:00');
            $this->overtime($e, '2026-07-14', 180);
        }
        $july = $this->period();
        [$hr2] = $this->makeStaff('hr');

        $results = $this->race([[$july->id, $this->hr->id], [$july->id, $hr2->id], [$july->id, $this->hr->id]]);
        foreach ($results as $r) {
            $this->assertTrue($r['ok'] || $r['code'] === 'PAYROLL_PROCESSING', json_encode($results));
        }
        $this->assertGreaterThanOrEqual(1, count(array_filter($results, fn ($r) => $r['ok'])));

        $this->assertSame(4, OvertimePayrollInclusion::query()->count());
        $this->assertSame(4, OvertimePayrollInclusion::query()->whereNotNull('active_request_id')->distinct()->count('overtime_request_id'));
        $this->assertSame(8, $this->overtimeLinesOf($july->fresh('run')), '2 bands x 4 employees, no duplicates');
        $this->assertSame(4, OvertimeRequest::query()->where('payroll_status', 'INCLUDED')->count());
    }

    public function test_two_periods_generated_at_the_same_instant_never_both_pay_a_late_approval(): void
    {
        $july = $this->toFinalized($this->period());
        foreach ($this->staff as $e) {
            $this->worked($e, '2026-07-28', '20:00');
            $this->overtime($e, '2026-07-28', 180);   // approved after July was finalized -> carry-over candidates
        }
        $august = $this->period('2026-08-01', '2026-08-31');
        $september = $this->period('2026-09-01', '2026-09-30');
        [$hr2] = $this->makeStaff('hr');

        $results = $this->race([[$august->id, $this->hr->id], [$september->id, $hr2->id]]);
        foreach ($results as $r) {
            $this->assertTrue($r['ok'], json_encode($results));
        }

        $this->assertSame(4, OvertimePayrollInclusion::query()->whereNotNull('active_request_id')->count());
        $this->assertSame(4, OvertimePayrollInclusion::query()->distinct()->count('overtime_request_id'));
        $paidIn = OvertimePayrollInclusion::query()->pluck('payroll_run_id')->unique();
        $this->assertNotContains($july->run->id, $paidIn->all(), 'the finalized run is never edited');
        $this->assertSame(8, $this->overtimeLinesOf($august->fresh('run')) + $this->overtimeLinesOf($september->fresh('run')));
    }
}
