<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceEvent;
use App\Models\WorkingCalendarDay;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\WorkplaceFixtures;
use Tests\TestCase;

/**
 * Genuine concurrency: separate PHP processes hit MySQL at the same instant.
 * Data is committed (no wrapping transaction) and every table is wiped before and after.
 */
class ConcurrencyTest extends TestCase
{
    use WorkplaceFixtures;

    private int $employeeId;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is disabled.');
        }
        if (! Schema::hasTable('attendance')) {
            Artisan::call('migrate', ['--force' => true]);
        }

        $this->wipe();
        $this->setUpWorkplace();

        // Around-the-clock shift on all seven days so the test does not depend on the weekday it runs.
        $this->shift = $this->makeShift('ALL', '00:00', '23:59');
        WorkingCalendarDay::query()->update(['is_working_day' => true]);
        [, $employee] = $this->makeStaff('employee', [], ['shift_id' => $this->shift->id]);
        $this->employeeId = $employee->id;
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

    /** @param list<array{0: string, 1: string}> $jobs [key, in|out] @return list<array<string, mixed>> */
    private function race(array $jobs): array
    {
        $startAt = microtime(true) + 5;
        $processes = [];

        foreach ($jobs as [$key, $type]) {
            $cmd = [PHP_BINARY, base_path('tests/Support/concurrent_attendance.php'), (string) $this->employeeId, $key, $type, (string) $startAt];
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

    public function test_two_simultaneous_check_ins_with_different_keys_create_exactly_one_attendance(): void
    {
        $results = $this->race([['race-key-aaaa-01', 'in'], ['race-key-bbbb-02', 'in']]);

        $ok = array_values(array_filter($results, fn ($r) => $r['ok']));
        $failed = array_values(array_filter($results, fn ($r) => ! $r['ok']));

        $this->assertCount(1, $ok, json_encode($results));
        $this->assertCount(1, $failed, json_encode($results));
        $this->assertSame('ALREADY_CHECKED_IN', $failed[0]['code']);
        $this->assertSame(1, Attendance::query()->count());
        $this->assertSame(1, AttendanceEvent::query()->count());
    }

    public function test_simultaneous_retries_of_the_same_key_yield_one_record_and_no_error(): void
    {
        $results = $this->race([['same-key-aaaa-01', 'in'], ['same-key-aaaa-01', 'in'], ['same-key-aaaa-01', 'in']]);

        foreach ($results as $r) {
            $this->assertTrue($r['ok'], 'a retry must never surface as an error: '.json_encode($results));
        }
        $this->assertCount(1, array_unique(array_column($results, 'event')), 'all retries return the same event');
        $this->assertCount(1, array_filter($results, fn ($r) => ! $r['replayed']), 'exactly one request did the work');
        $this->assertSame(1, Attendance::query()->count());
        $this->assertSame(1, AttendanceEvent::query()->count());
    }

    public function test_two_simultaneous_check_outs_with_different_keys_close_the_day_once(): void
    {
        $this->assertTrue($this->race([['seed-in-key-001', 'in']])[0]['ok']);

        $results = $this->race([['out-key-aaaa-001', 'out'], ['out-key-bbbb-002', 'out']]);

        $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']), json_encode($results));
        $this->assertContains('NOT_CHECKED_IN', array_column($results, 'code'));
        $this->assertSame(1, AttendanceEvent::query()->where('type', 'CHECK_OUT')->count());
        $this->assertNotNull(Attendance::query()->firstOrFail()->check_out_at);
    }
}
