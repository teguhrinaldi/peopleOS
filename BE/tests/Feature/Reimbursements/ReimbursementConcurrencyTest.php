<?php

namespace Tests\Feature\Reimbursements;

use App\Models\Activity;
use App\Models\AppNotification;
use App\Models\ExpensePayment;
use App\Models\ExpenseRequest;
use App\Models\ExpenseRequestEvent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ReimbursementFixtures;
use Tests\TestCase;

/**
 * Real concurrency: separate PHP processes act on MySQL at the same instant (no wrapping test transaction).
 * Proves the row lock + unique constraints + idempotency key: one payment, one approval, unique request numbers.
 */
class ReimbursementConcurrencyTest extends TestCase
{
    use ReimbursementFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is disabled.');
        }
        if (! Schema::hasTable('expense_requests')) {
            Artisan::call('migrate', ['--force' => true]);
        }

        $this->wipe();
        $this->setUpReimbursements();
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

    /** @param list<list<string>> $jobs [action, targetId, userId, extra?] @return list<array<string, mixed>> */
    private function race(array $jobs): array
    {
        $startAt = microtime(true) + 6;
        $processes = [];
        foreach ($jobs as $job) {
            [$action, $target, $user] = $job;
            $cmd = [PHP_BINARY, base_path('tests/Support/concurrent_reimbursement.php'), $action, (string) $target, (string) $user, (string) $startAt];
            if (isset($job[3])) {
                $cmd[] = (string) $job[3];
            }
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
            $this->assertNotSame('CRASH', $decoded['code'] ?? null, json_encode($decoded));
            $results[] = $decoded;
        }

        return $results;
    }

    public function test_concurrent_payments_with_different_keys_pay_exactly_once(): void
    {
        $claim = $this->scheduled();
        $results = $this->race([
            ['pay', $claim->id, $this->hr->id, 'key-a'],
            ['pay', $claim->id, $this->hr->id, 'key-b'],
            ['pay', $claim->id, $this->admin->id, 'key-c'],
            ['pay', $claim->id, $this->admin->id, 'key-d'],
        ]);

        $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']), json_encode($results));
        $this->assertSame(['ALREADY_PAID', 'ALREADY_PAID', 'ALREADY_PAID'], array_values(array_column(array_filter($results, fn ($r) => ! $r['ok']), 'code')));
        $this->assertSame(1, ExpensePayment::query()->where('expense_request_id', $claim->id)->count());
        $this->assertSame('PAID', $claim->fresh()->status->value);
        $this->assertSame(1, ExpenseRequestEvent::query()->where('expense_request_id', $claim->id)->where('action', 'paid')->count());
        $this->assertSame(1, Activity::query()->where('action', 'expense.paid')->where('entity_id', $claim->id)->count());
        $this->assertSame(1, AppNotification::query()->where('type', 'expense.paid')->where('entity_id', $claim->id)->count());
    }

    public function test_concurrent_retries_with_the_same_key_return_the_same_payment(): void
    {
        $claim = $this->scheduled();
        $results = $this->race(array_fill(0, 4, ['pay', $claim->id, $this->hr->id, 'same-key']));

        $this->assertCount(4, array_filter($results, fn ($r) => $r['ok']), json_encode($results));
        $this->assertCount(1, array_unique(array_column($results, 'payment_id')));
        $this->assertCount(1, array_filter($results, fn ($r) => $r['replayed'] === false), 'exactly one call created the payment');
        $this->assertSame(1, ExpensePayment::query()->count());
        $this->assertSame(1, AppNotification::query()->where('type', 'expense.paid')->count());
    }

    public function test_two_approvers_at_once_approve_once(): void
    {
        $claim = $this->underReview();
        $results = $this->race([['approve', $claim->id, $this->hr->id], ['approve', $claim->id, $this->admin->id], ['approve', $claim->id, $this->manager->id]]);

        $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']), json_encode($results));
        $this->assertSame(['INVALID_TRANSITION', 'INVALID_TRANSITION'], array_values(array_column(array_filter($results, fn ($r) => ! $r['ok']), 'code')));
        $this->assertSame(1, ExpenseRequestEvent::query()->where('expense_request_id', $claim->id)->where('action', 'approved')->count());
        $this->assertSame(1, Activity::query()->where('action', 'expense.approved')->count());
        $this->assertSame(1, AppNotification::query()->where('type', 'expense.approved')->count());
    }

    public function test_request_numbers_stay_unique_and_gapless_under_concurrent_creation(): void
    {
        $category = $this->category('PARKING_TOLL')->id;
        $jobs = [];
        foreach ([$this->employee, $this->strangerEmployee, $this->hrEmployee, $this->managerEmployee] as $who) {
            $jobs[] = ['create', $who->id, $who->user_id, $category];
            $jobs[] = ['create', $who->id, $who->user_id, $category];
        }
        $results = $this->race($jobs);

        $this->assertCount(8, array_filter($results, fn ($r) => $r['ok']), json_encode($results));
        $numbers = array_column($results, 'number');
        sort($numbers);
        $period = now()->setTimezone('Asia/Jakarta')->format('Ym');
        $this->assertSame(array_map(fn ($n) => sprintf('RB-%s-%04d', $period, $n), range(1, 8)), $numbers);
        $this->assertSame(8, ExpenseRequest::query()->distinct()->count('request_number'));
    }
}
