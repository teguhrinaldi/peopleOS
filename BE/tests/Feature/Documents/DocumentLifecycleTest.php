<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentStatus;
use App\Models\Activity;
use App\Models\AppNotification;
use App\Models\Document;
use App\Models\Employee;
use App\Models\User;
use App\Services\Documents\DocumentService;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DocumentFixtures;
use Tests\TestCase;

/** Update / archive / restore / delete, status derivation, the refresh job, notifications and audit. */
class DocumentLifecycleTest extends TestCase
{
    use DocumentFixtures, RefreshDatabase;

    private User $me;

    private Employee $meEmployee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
        [$this->me, $this->meEmployee] = $this->makeStaff('employee');
        $this->at('2026-09-30 10:00');
    }

    private function service(): DocumentService
    {
        return app(DocumentService::class);
    }

    public function test_update_metadata_recomputes_status_and_is_audited_without_paths(): void
    {
        $doc = $this->makeDocument($this->meEmployee, ['type' => 'IDENTIFICATION', 'expiry_date' => '2027-06-01', 'title' => 'ID']);

        $this->actingAsApi($this->hr)->patchJson("/api/v1/documents/{$doc->id}", [
            'title' => 'National ID (copy)', 'expiry_date' => '2026-10-05', 'notes' => 'renewal requested',
        ])->assertOk()->assertJsonPath('data.title', 'National ID (copy)')->assertJsonPath('data.status', 'EXPIRING')
            ->assertJsonPath('data.expiry_date', '2026-10-05')->assertJsonPath('data.days_until_expiry', 5);

        $this->assertSame(DocumentStatus::Expiring, $doc->fresh()->status);

        // Clearing the expiry of a type that requires one is refused.
        $this->patchJson("/api/v1/documents/{$doc->id}", ['expiry_date' => null])
            ->assertUnprocessable()->assertJsonPath('error.code', 'EXPIRY_DATE_REQUIRED');
        // Changing to a type that is not allowed for employee documents is refused.
        $this->patchJson("/api/v1/documents/{$doc->id}", ['document_type_id' => $this->type('COMPANY_DOCUMENT')->id])
            ->assertUnprocessable()->assertJsonPath('error.code', 'DOCUMENT_TYPE_NOT_ALLOWED');

        $log = Activity::query()->where('action', 'document.updated')->firstOrFail();
        $this->assertSame('2026-10-05', $log->new_values['expiry_date']);
        $this->assertSame('2027-06-01', $log->old_values['expiry_date']);
        $this->assertSame('EXPIRING', $log->new_values['status']);
    }

    public function test_archive_and_restore_transitions(): void
    {
        $doc = $this->makeDocument($this->meEmployee, ['type' => 'IDENTIFICATION', 'expiry_date' => '2026-09-01']);
        $this->assertSame(DocumentStatus::Expired, $doc->status);

        $this->actingAsApi($this->hr);
        $this->postJson("/api/v1/documents/{$doc->id}/restore")->assertStatus(409)->assertJsonPath('error.code', 'DOCUMENT_NOT_ARCHIVED');
        $this->postJson("/api/v1/documents/{$doc->id}/archive", ['reason' => 'Replaced'])->assertOk()
            ->assertJsonPath('data.status', 'ARCHIVED')->assertJsonPath('data.archived_by.id', $this->hr->id);
        $this->postJson("/api/v1/documents/{$doc->id}/archive")->assertStatus(409)->assertJsonPath('error.code', 'DOCUMENT_ALREADY_ARCHIVED');
        $this->patchJson("/api/v1/documents/{$doc->id}", ['title' => 'x'])->assertStatus(409)->assertJsonPath('error.code', 'DOCUMENT_ARCHIVED');

        // Restore goes back to the DERIVED state for today, not to a remembered one.
        $this->postJson("/api/v1/documents/{$doc->id}/restore")->assertOk()->assertJsonPath('data.status', 'EXPIRED')
            ->assertJsonPath('data.archived_at', null);
        $this->assertSame(DocumentStatus::Expired, $doc->fresh()->status);
    }

    public function test_job_never_expires_an_archived_or_deleted_document(): void
    {
        $archived = $this->makeDocument($this->meEmployee, ['type' => 'IDENTIFICATION', 'expiry_date' => '2026-10-02']);
        $deleted = $this->makeDocument($this->meEmployee, ['type' => 'IDENTIFICATION', 'expiry_date' => '2026-10-02']);
        $this->service()->archive($this->hr, $archived);
        $this->service()->delete($this->hr, $deleted);

        $this->at('2026-12-01 10:00');
        $this->artisan('documents:refresh-status')->assertSuccessful();

        $this->assertSame(DocumentStatus::Archived, $archived->fresh()->status);
        $this->assertSame(DocumentStatus::Expiring, Document::withTrashed()->findOrFail($deleted->id)->status);
        $this->assertSame(0, AppNotification::query()->count());
    }

    public function test_status_derivation_boundaries_through_the_job(): void
    {
        $docs = [];
        foreach (['2026-09-29' => 'EXPIRED', '2026-09-30' => 'EXPIRING', '2026-10-30' => 'EXPIRING', '2026-10-31' => 'ACTIVE'] as $date => $expected) {
            $docs[$date] = [$this->makeDocument($this->meEmployee, ['type' => 'CERTIFICATE', 'expiry_date' => $date]), $expected];
        }
        Document::query()->update(['status' => 'ACTIVE']); // simulate stale stored statuses

        $this->artisan('documents:refresh-status')->assertSuccessful();

        foreach ($docs as $date => [$doc, $expected]) {
            $this->assertSame($expected, $doc->fresh()->status->value, "expiry {$date}");
        }

        // A day later every window moves by one day.
        $this->at('2026-10-01 10:00');
        $this->artisan('documents:refresh-status')->assertSuccessful();
        $this->assertSame('EXPIRED', $docs['2026-09-30'][0]->fresh()->status->value);
        $this->assertSame('EXPIRING', $docs['2026-10-31'][0]->fresh()->status->value);
    }

    public function test_status_uses_the_company_timezone_not_utc(): void
    {
        $doc = $this->makeDocument($this->meEmployee, ['type' => 'CERTIFICATE', 'expiry_date' => '2026-09-30']);

        // 2026-09-30 23:30 UTC is already 2026-10-01 06:30 in Asia/Jakarta: the document has expired.
        $this->travelTo(CarbonImmutable::parse('2026-09-30 23:30', 'UTC'));
        $this->artisan('documents:refresh-status')->assertSuccessful();
        $this->assertSame(DocumentStatus::Expired, $doc->fresh()->status);

        $this->actingAsApi($this->me)->getJson("/api/v1/documents/{$doc->id}")->assertJsonPath('data.status', 'EXPIRED')
            ->assertJsonPath('data.days_until_expiry', -1);

        // Still 2026-09-30 in Jakarta at 16:59 UTC (23:59 local): expiring, not expired.
        $this->travelTo(CarbonImmutable::parse('2026-09-30 16:59', 'UTC'));
        $this->getJson("/api/v1/documents/{$doc->id}")->assertJsonPath('data.status', 'EXPIRING');
    }

    public function test_expiring_window_follows_the_setting(): void
    {
        $doc = $this->makeDocument($this->meEmployee, ['type' => 'CERTIFICATE', 'expiry_date' => '2026-10-20']);
        $this->assertSame(DocumentStatus::Expiring, $doc->status);

        app(Settings::class)->set(['documents.expiring_days' => 10]);
        $this->artisan('documents:refresh-status')->assertSuccessful();
        $this->assertSame(DocumentStatus::Active, $doc->fresh()->status);
    }

    public function test_job_notifies_once_per_state_and_running_twice_is_idempotent(): void
    {
        [$admin] = $this->makeStaff('admin');
        [$manager] = $this->makeStaff('manager');
        $doc = $this->makeDocument($this->meEmployee, ['type' => 'IDENTIFICATION', 'expiry_date' => '2026-10-10', 'title' => 'National ID']);
        $hidden = $this->makeDocument($this->meEmployee, ['type' => 'OTHER', 'expiry_date' => '2026-10-10', 'visible_to_employee' => false]);

        $this->artisan('documents:refresh-status')->assertSuccessful();
        $this->artisan('documents:refresh-status')->assertSuccessful();

        $expiring = AppNotification::query()->where('type', 'document.expiring');
        // Visible doc: employee + HR + admin. Hidden doc: HR + admin only.
        $this->assertSame(5, (clone $expiring)->count());
        $this->assertSame(1, (clone $expiring)->where('user_id', $this->me->id)->count());
        $this->assertSame(0, AppNotification::query()->where('user_id', $manager->id)->count(), 'managers get nothing extra');
        $this->assertSame(2, (clone $expiring)->where('user_id', $admin->id)->count());
        $mine = (clone $expiring)->where('user_id', $this->me->id)->firstOrFail();
        $this->assertSame('Document expiring', $mine->title);
        $this->assertSame($doc->id, $mine->data['document_id']);
        $this->assertStringNotContainsString('documents/', json_encode($mine->toArray()));
        $this->assertSame('EXPIRING', $doc->fresh()->notified_status);
        $this->assertSame('EXPIRING', $hidden->fresh()->notified_status);

        // The day after expiry each recipient gets exactly one "expired" notification.
        $this->at('2026-10-11 10:00');
        $this->artisan('documents:refresh-status')->assertSuccessful();
        $this->artisan('documents:refresh-status')->assertSuccessful();
        $this->assertSame(5, AppNotification::query()->where('type', 'document.expired')->count());
        $this->assertSame('Document expired', AppNotification::query()->where('type', 'document.expired')->where('user_id', $this->me->id)->value('title'));

        // A renewed expiry date starts a new reminder cycle.
        $this->service()->update($this->hr, $doc, ['expiry_date' => '2026-11-01']);
        $this->artisan('documents:refresh-status')->assertSuccessful();
        $this->assertSame(2, AppNotification::query()->where('type', 'document.expiring')->where('user_id', $this->me->id)->count());
    }

    public function test_company_document_expiry_notifies_hr_only(): void
    {
        $this->makeDocument(null, ['type' => 'OTHER', 'expiry_date' => '2026-10-05', 'visible_to_employee' => true]);

        $this->artisan('documents:refresh-status')->assertSuccessful();

        $this->assertSame(1, AppNotification::query()->count());
        $this->assertSame($this->hr->id, AppNotification::query()->value('user_id'));
    }

    public function test_delete_is_soft_keeps_the_file_and_is_audited(): void
    {
        $doc = $this->makeDocument($this->meEmployee);
        $path = Document::query()->findOrFail($doc->id)->file_path;

        $this->actingAsApi($this->hr)->deleteJson("/api/v1/documents/{$doc->id}")->assertOk()->assertJsonPath('data.deleted', true);
        $this->deleteJson("/api/v1/documents/{$doc->id}")->assertNotFound();

        $this->assertSoftDeleted('documents', ['id' => $doc->id, 'deleted_by' => $this->hr->id]);
        $this->assertSame([$path], $this->storedFiles(), 'file retained for the retention period');
    }

    public function test_every_mutation_is_audited_and_no_audit_row_contains_a_file_path(): void
    {
        $doc = $this->makeDocument($this->meEmployee, ['title' => 'Contract']);
        $path = Document::query()->findOrFail($doc->id)->file_path;

        $this->service()->update($this->hr, $doc, ['title' => 'Contract v2']);
        $this->service()->archive($this->hr, $doc, 'old');
        $this->service()->restore($this->hr, $doc);
        $this->service()->delete($this->hr, $doc);

        $rows = Activity::query()->where('entity_type', Document::class)->where('entity_id', $doc->id)->orderBy('id')->get();
        $this->assertSame(
            ['document.uploaded', 'document.updated', 'document.archived', 'document.restored', 'document.deleted'],
            $rows->pluck('action')->all(),
        );
        foreach ($rows as $row) {
            $this->assertSame($this->hr->id, $row->actor_id);
            $json = json_encode([$row->old_values, $row->new_values, $row->description]);
            $this->assertStringNotContainsString($path, $json);
            $this->assertStringNotContainsString('file_path', $json);
            $this->assertStringNotContainsString('documents/', $json);
        }
        $this->assertSame(hash('sha256', $this->pdfBytes()), $rows[0]->new_values['sha256']);
    }

    public function test_hr_list_filters_by_status_and_expiring_window(): void
    {
        $expired = $this->makeDocument($this->meEmployee, ['type' => 'CERTIFICATE', 'expiry_date' => '2026-09-01']);
        $soon = $this->makeDocument($this->meEmployee, ['type' => 'CERTIFICATE', 'expiry_date' => '2026-10-03']);
        $later = $this->makeDocument($this->meEmployee, ['type' => 'CERTIFICATE', 'expiry_date' => '2026-10-25']);
        $this->makeDocument($this->meEmployee, ['type' => 'CERTIFICATE']);

        $this->actingAsApi($this->hr);
        $ids = fn (string $q) => collect($this->getJson('/api/v1/documents?scope=all&'.$q)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$expired->id], $ids('status=EXPIRED'));
        $this->assertSame([$soon->id, $later->id], $ids('status=EXPIRING'));
        $this->assertSame([$soon->id], $ids('expiring_within_days=7'));
        $this->assertSame([$soon->id, $later->id], $ids('expiring_within_days=30'));

        $ordered = collect($this->getJson('/api/v1/documents?scope=all&sort=expiry')->json('data'))->pluck('id')->all();
        $this->assertSame([$expired->id, $soon->id, $later->id], array_slice($ordered, 0, 3), 'soonest expiry first, no-expiry last');
    }
}
