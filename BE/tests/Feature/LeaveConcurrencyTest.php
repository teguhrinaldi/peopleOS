<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\User;
use App\Services\Leave\LeaveService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\LeaveFixtures;
use Tests\Support\WorkplaceFixtures;
use Tests\TestCase;

/**
 * Real concurrency: separate PHP processes act on MySQL at the same instant (no wrapping transaction).
 * Proves the employee-row lock, the balance lock and the no-overlap unique index together.
 */
class LeaveConcurrencyTest extends TestCase
{
    use LeaveFixtures, WorkplaceFixtures;

    private User $user;

    private Employee $employee;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is disabled.');
        }
        if (! Schema::hasTable('leave_requests')) {
            Artisan::call('migrate', ['--force' => true]);
        }

        $this->wipe();
        $this->setUpWorkplace();
        $this->setUpLeave();

        [$this->manager, $managerEmployee] = $this->makeStaff('manager');
        [$this->user, $this->employee] = $this->makeStaff('employee', [], ['manager_id' => $managerEmployee->id]);
    }

    protected function tearDown(): void
    {
        $this->wipe();
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

    /** Future working dates, so the test never depends on the day it runs. */
    private function draft(string $startOffset, string $endOffset): LeaveRequest
    {
        $monday = now()->addWeeks(2)->startOfWeek();

        return app(LeaveService::class)->create($this->employee, $this->user, $this->leavePayload(
            $this->annual,
            $monday->copy()->modify($startOffset)->toDateString(),
            $monday->copy()->modify($endOffset)->toDateString(),
        ), submit: false);
    }

    /** @param list<array{0: string, 1: int, 2: int}> $jobs [action, requestId, userId] @return list<array<string, mixed>> */
    private function race(array $jobs): array
    {
        $startAt = microtime(true) + 5;
        $processes = [];
        foreach ($jobs as [$action, $requestId, $userId]) {
            $cmd = [PHP_BINARY, base_path('tests/Support/concurrent_leave.php'), $action, (string) $requestId, (string) $userId, (string) $startAt];
            $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), getenv());
            $processes[] = [$proc, $pipes];
        }

        $results = [];
        foreach ($processes as [$proc, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            proc_close($proc);
            $decoded = json_decode(trim($out), true);
            $this->assertIsArray($decoded, "child produced no JSON. stdout={$out} stderr={$err}");
            $results[] = $decoded;
        }

        return $results;
    }

    private function balance(): LeaveBalance
    {
        return LeaveBalance::query()->where('employee_id', $this->employee->id)->where('leave_type_id', $this->annual->id)->firstOrFail();
    }

    public function test_two_overlapping_requests_submitted_at_once_leave_exactly_one_pending(): void
    {
        $a = $this->draft('+0 day', '+2 days');   // Mon-Wed
        $b = $this->draft('+1 day', '+3 days');   // Tue-Thu (overlaps)

        $results = $this->race([['submit', $a->id, $this->user->id], ['submit', $b->id, $this->user->id]]);

        $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']), json_encode($results));
        $this->assertContains('LEAVE_OVERLAP', array_column($results, 'code'));
        $this->assertSame(1, LeaveRequest::query()->where('status', 'PENDING')->count());
        $this->assertSame('3.0', (string) $this->balance()->reserved);
        $this->assertSame(6, LeaveRequestDay::query()->where('blocking', true)->count());
    }

    public function test_two_requests_that_together_exceed_the_balance_cannot_both_reserve_it(): void
    {
        $a = $this->draft('+0 day', '+1 week +2 days');   // 8 working days
        $b = $this->draft('+2 weeks', '+2 weeks +4 days'); // 5 working days; 8 + 5 > 12

        $results = $this->race([['submit', $a->id, $this->user->id], ['submit', $b->id, $this->user->id]]);

        $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']), json_encode($results));
        $this->assertContains('LEAVE_INSUFFICIENT_BALANCE', array_column($results, 'code'));
        $reserved = (string) $this->balance()->reserved;
        $this->assertContains($reserved, ['8.0', '5.0'], 'the reserve must equal exactly the one successful request');
        $this->assertGreaterThanOrEqual(0, (float) $this->balance()->available());
    }

    public function test_two_approvers_deciding_at_once_approve_once_and_count_the_days_once(): void
    {
        [$hr] = $this->makeStaff('hr');
        $request = $this->draft('+0 day', '+1 day');
        app(LeaveService::class)->submit($this->user, $request);

        $results = $this->race([['approve', $request->id, $this->manager->id], ['approve', $request->id, $hr->id]]);

        $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']), json_encode($results));
        $this->assertContains('REQUEST_NOT_PENDING', array_column($results, 'code'));
        $balance = $this->balance();
        $this->assertSame('2.0', (string) $balance->used);
        $this->assertSame('0.0', (string) $balance->reserved);
    }
}
