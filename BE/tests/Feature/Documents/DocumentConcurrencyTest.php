<?php

namespace Tests\Feature\Documents;

use App\Models\AppNotification;
use App\Models\Document;
use App\Services\Documents\DocumentTypeService;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\WorkplaceFixtures;
use Tests\TestCase;

/**
 * Genuine concurrency: several PHP processes run the refresh job at the same instant against committed
 * MySQL data. Every recipient must be notified exactly once per document and state.
 */
class DocumentConcurrencyTest extends TestCase
{
    use WorkplaceFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is disabled.');
        }
        if (! Schema::hasTable('documents')) {
            Artisan::call('migrate', ['--force' => true]);
        }

        $this->wipe();
        $this->setUpWorkplace();
        app(DocumentTypeService::class)->installDefaults();
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
        app(CompanyContext::class)->forget();
    }

    /** @return list<array<string, mixed>> */
    private function race(int $workers): array
    {
        $startAt = microtime(true) + 5;
        $processes = [];
        for ($i = 0; $i < $workers; $i++) {
            $proc = proc_open([PHP_BINARY, base_path('tests/Support/concurrent_documents.php'), (string) $startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), getenv());
            $processes[] = [$proc, $pipes];
        }

        $results = [];
        foreach ($processes as [$proc, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            proc_close($proc);
            $decoded = json_decode(trim($stdout), true);
            $this->assertIsArray($decoded, "child produced no JSON. stdout={$stdout} stderr={$stderr}");
            $this->assertTrue($decoded['ok'], json_encode($decoded));
            $results[] = $decoded;
        }

        return $results;
    }

    public function test_concurrent_refresh_runs_notify_each_recipient_exactly_once(): void
    {
        [$hr] = $this->makeStaff('hr');
        [$admin] = $this->makeStaff('admin');
        $owners = [];
        for ($i = 0; $i < 4; $i++) {
            $owners[] = $this->makeStaff('employee');
        }

        $type = DB::table('document_types')->where('code', 'CERTIFICATE')->value('id');
        $today = app(CompanyContext::class)->today();
        foreach ($owners as $n => [, $employee]) {
            foreach ([3, -2] as $offset) { // one expiring, one already expired per employee
                Document::query()->create([
                    'company_id' => $this->company->id, 'employee_id' => $employee->id, 'document_type_id' => $type,
                    'title' => "Cert {$n} {$offset}", 'file_disk' => 'local', 'file_path' => 'documents/'.$employee->id.'/'.Str::random(40),
                    'file_extension' => 'pdf', 'original_name' => 'c.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10,
                    'sha256' => str_repeat('a', 64), 'expiry_date' => $today->addDays($offset)->toDateString(), 'status' => 'ACTIVE',
                    'visible_to_employee' => true, 'uploaded_by' => $hr->id, 'uploaded_at' => now(),
                ]);
            }
        }

        $results = $this->race(4);

        // 8 documents x (owner + hr + admin) = 24 notifications, no matter how many workers raced.
        $this->assertSame(24, AppNotification::query()->count());
        $this->assertSame(24, array_sum(array_column($results, 'notified')), 'the work was split, never duplicated');
        $this->assertSame(8, array_sum(array_column($results, 'updated')), 'each stale status was fixed by exactly one worker');
        $this->assertSame(4, Document::query()->where('status', 'EXPIRING')->where('notified_status', 'EXPIRING')->count());
        $this->assertSame(4, Document::query()->where('status', 'EXPIRED')->where('notified_status', 'EXPIRED')->count());
        $this->assertSame(8, AppNotification::query()->where('user_id', $admin->id)->count());

        // A further (sequential) run is a no-op.
        $again = $this->race(1);
        $this->assertSame(0, $again[0]['notified']);
        $this->assertSame(24, AppNotification::query()->count());
    }
}
