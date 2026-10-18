<?php

namespace Tests\Feature\Documents;

use App\Models\Document;
use App\Models\Employee;
use App\Models\User;
use App\Services\Documents\DocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DocumentFixtures;
use Tests\TestCase;

/** Visibility, scope and IDOR rules for listing, reading and downloading documents. */
class DocumentAccessTest extends TestCase
{
    use DocumentFixtures, RefreshDatabase;

    private User $me;

    private Employee $meEmployee;

    private Employee $colleague;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
        [$this->me, $this->meEmployee] = $this->makeStaff('employee');
        [, $this->colleague] = $this->makeStaff('employee');
    }

    private function ids($response): array
    {
        return collect($response->json('data'))->pluck('id')->sort()->values()->all();
    }

    public function test_employee_lists_only_own_visible_and_company_visible_documents(): void
    {
        $mine = $this->makeDocument($this->meEmployee, ['title' => 'My contract']);
        $hrOnly = $this->makeDocument($this->meEmployee, ['type' => 'OTHER', 'title' => 'HR note', 'visible_to_employee' => false]);
        $theirs = $this->makeDocument($this->colleague, ['title' => 'Their contract']);
        $handbook = $this->makeDocument(null, ['title' => 'Handbook']);
        $companyHrOnly = $this->makeDocument(null, ['type' => 'OTHER', 'title' => 'Lease', 'visible_to_employee' => false]);

        $res = $this->actingAsApi($this->me)->getJson('/api/v1/documents')->assertOk()
            ->assertJsonPath('success', true)->assertJsonPath('meta.total', 2);

        $this->assertSame(collect([$mine->id, $handbook->id])->sort()->values()->all(), $this->ids($res));
        $res->assertJsonMissingPath('data.0.notes');
        $this->assertStringNotContainsString('documents/', $res->getContent(), 'no storage path in JSON');
        $this->assertStringNotContainsString('file_path', $res->getContent());

        foreach ([$hrOnly, $theirs, $companyHrOnly] as $hidden) {
            $this->getJson("/api/v1/documents/{$hidden->id}")->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
            $this->get("/api/v1/documents/{$hidden->id}/download")->assertNotFound();
        }

        $this->getJson("/api/v1/documents/{$mine->id}")->assertOk()->assertJsonPath('data.title', 'My contract')
            ->assertJsonPath('data.is_company_document', false)->assertJsonPath('data.status', 'ACTIVE');
        $this->getJson("/api/v1/documents/{$handbook->id}")->assertOk()->assertJsonPath('data.is_company_document', true);
    }

    public function test_guessed_ids_are_indistinguishable_from_missing_ones(): void
    {
        $theirs = $this->makeDocument($this->colleague);

        $foreign = $this->actingAsApi($this->me)->getJson("/api/v1/documents/{$theirs->id}");
        $missing = $this->getJson('/api/v1/documents/999999');

        $foreign->assertNotFound();
        $missing->assertNotFound();
        $this->assertSame($missing->json(), $foreign->json(), 'identical body: existence is not leaked');
        $this->get("/api/v1/documents/{$theirs->id}/download")->assertNotFound();
        $this->get('/api/v1/documents/999999/download')->assertNotFound();
    }

    public function test_employee_id_filter_cannot_widen_self_service(): void
    {
        $this->makeDocument($this->colleague);
        $this->makeDocument($this->colleague, ['type' => 'CERTIFICATE']);

        $this->actingAsApi($this->me)->getJson("/api/v1/documents?employee_id={$this->colleague->id}")
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/documents?scope=all')->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
        $this->getJson("/api/v1/documents?scope=all&employee_id={$this->colleague->id}")->assertForbidden();
    }

    public function test_hr_only_document_is_invisible_to_its_owner_until_made_visible(): void
    {
        $note = $this->makeDocument($this->meEmployee, ['type' => 'OTHER', 'title' => 'Reference check', 'visible_to_employee' => false]);

        $this->actingAsApi($this->me)->getJson("/api/v1/documents/{$note->id}")->assertNotFound();
        $this->get("/api/v1/documents/{$note->id}/download")->assertNotFound();

        app(DocumentService::class)->update($this->hr, $note, ['visible_to_employee' => true]);

        $this->getJson("/api/v1/documents/{$note->id}")->assertOk();
        $this->get("/api/v1/documents/{$note->id}/download")->assertOk();
    }

    public function test_archived_documents_are_hidden_from_self_service_but_visible_to_hr(): void
    {
        $doc = $this->makeDocument($this->meEmployee);
        app(DocumentService::class)->archive($this->hr, $doc, 'superseded');

        $this->actingAsApi($this->me)->getJson('/api/v1/documents')->assertJsonPath('meta.total', 0);
        $this->getJson("/api/v1/documents/{$doc->id}")->assertNotFound();

        $this->actingAsApi($this->hr)->getJson('/api/v1/documents?scope=all&status=archived')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.status', 'ARCHIVED')
            ->assertJsonPath('data.0.archive_reason', 'superseded');
    }

    public function test_hr_sees_everything_with_filters(): void
    {
        $contract = $this->makeDocument($this->meEmployee);
        $note = $this->makeDocument($this->meEmployee, ['type' => 'OTHER', 'visible_to_employee' => false]);
        $other = $this->makeDocument($this->colleague, ['type' => 'CERTIFICATE']);
        $company = $this->makeDocument(null);

        $this->actingAsApi($this->hr);
        $this->assertSame(collect([$contract->id, $note->id, $other->id, $company->id])->sort()->values()->all(),
            $this->ids($this->getJson('/api/v1/documents?scope=all')->assertOk()));
        $this->assertSame([$contract->id, $note->id], $this->ids($this->getJson("/api/v1/documents?scope=all&employee_id={$this->meEmployee->id}")));
        $this->assertSame([$other->id], $this->ids($this->getJson('/api/v1/documents?scope=all&document_type_id='.$this->type('CERTIFICATE')->id)));
        $this->assertSame([$company->id], $this->ids($this->getJson('/api/v1/documents?scope=all&company_only=1')));

        $this->getJson("/api/v1/documents/{$note->id}")->assertOk()->assertJsonPath('data.visible_to_employee', false)
            ->assertJsonStructure(['data' => ['notes', 'uploaded_by', 'file' => ['name', 'mime_type', 'size_bytes', 'sha256']]]);
        $this->get("/api/v1/documents/{$note->id}/download")->assertOk();
    }

    public function test_manager_gets_no_access_to_team_documents(): void
    {
        [$manager, $managerEmployee] = $this->makeStaff('manager');
        [, $report] = $this->makeStaff('employee', [], ['manager_id' => $managerEmployee->id]);
        $reportDoc = $this->makeDocument($report);

        $this->actingAsApi($manager);
        $this->getJson('/api/v1/documents?scope=all')->assertForbidden();
        $this->getJson("/api/v1/documents?employee_id={$report->id}")->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson("/api/v1/documents/{$reportDoc->id}")->assertNotFound();
        $this->get("/api/v1/documents/{$reportDoc->id}/download")->assertNotFound();
        $this->postJson('/api/v1/documents', ['document_type_id' => 1, 'title' => 'x'])->assertForbidden();
        $this->patchJson("/api/v1/documents/{$reportDoc->id}", ['title' => 'x'])->assertForbidden();
        $this->postJson("/api/v1/documents/{$reportDoc->id}/archive")->assertForbidden();
        $this->deleteJson("/api/v1/documents/{$reportDoc->id}")->assertForbidden();
        $this->assertNotSoftDeleted($reportDoc);
    }

    public function test_user_without_any_document_permission_gets_403_everywhere(): void
    {
        [$kiosk] = $this->makeNoDocumentStaff();
        $doc = $this->makeDocument(null);

        $this->actingAsApi($kiosk);
        $this->getJson('/api/v1/documents')->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
        $this->getJson("/api/v1/documents/{$doc->id}")->assertForbidden();
        $this->getJson('/api/v1/documents/999999')->assertForbidden(); // same answer for a missing id
        $this->get("/api/v1/documents/{$doc->id}/download")->assertForbidden();
        $this->getJson('/api/v1/document-types')->assertForbidden();
    }

    public function test_management_endpoints_answer_403_before_revealing_existence(): void
    {
        $theirs = $this->makeDocument($this->colleague);

        $this->actingAsApi($this->me);
        foreach ([$theirs->id, 999999] as $id) {
            $this->patchJson("/api/v1/documents/{$id}", ['title' => 'Hacked'])->assertForbidden();
            $this->postJson("/api/v1/documents/{$id}/archive")->assertForbidden();
            $this->postJson("/api/v1/documents/{$id}/restore")->assertForbidden();
            $this->deleteJson("/api/v1/documents/{$id}")->assertForbidden();
        }
        $this->assertSame($theirs->title, $theirs->fresh()->title);
    }

    public function test_soft_deleted_documents_are_not_listed_shown_or_downloadable(): void
    {
        $doc = $this->makeDocument($this->meEmployee);
        app(DocumentService::class)->delete($this->hr, $doc);

        $this->actingAsApi($this->me)->getJson('/api/v1/documents')->assertJsonPath('meta.total', 0);
        $this->getJson("/api/v1/documents/{$doc->id}")->assertNotFound();
        $this->get("/api/v1/documents/{$doc->id}/download")->assertNotFound();

        $this->actingAsApi($this->hr)->getJson('/api/v1/documents?scope=all')->assertJsonPath('meta.total', 0);
        $this->get("/api/v1/documents/{$doc->id}/download")->assertNotFound();
        $this->patchJson("/api/v1/documents/{$doc->id}", ['title' => 'x'])->assertNotFound();
    }

    public function test_download_streams_the_exact_file_with_safe_headers(): void
    {
        $bytes = $this->pdfBytes('download-check');
        $doc = $this->makeDocument($this->meEmployee, ['title' => 'Contract'], $this->pdf('Kontrak "Kerja" Café.pdf', $bytes));

        $res = $this->actingAsApi($this->me)->get("/api/v1/documents/{$doc->id}/download")->assertOk();

        $this->assertSame($bytes, $res->streamedContent());
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertSame('attachment; filename="Kontrak _Kerja_ Cafe.pdf"', $res->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $res->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertSame((string) strlen($bytes), $res->headers->get('Content-Length'));
        $this->assertSame(hash('sha256', $bytes), $res->headers->get('X-Document-Sha256'));
    }

    public function test_files_are_not_reachable_through_any_public_storage_url(): void
    {
        $doc = $this->makeDocument($this->meEmployee);
        $path = Document::query()->findOrFail($doc->id)->file_path;

        $this->assertStringStartsWith("documents/{$this->meEmployee->id}/", $path);
        $this->assertContains($this->get('/storage/'.$path)->getStatusCode(), [403, 404], 'unsigned private-disk URL is refused');
        $this->assertContains($this->actingAsApi($this->me)->get('/storage/'.$path)->getStatusCode(), [403, 404], 'even for the owner');
        $this->assertFalse(file_exists(public_path('storage/'.$path)));
    }

    public function test_employee_filters_by_type_and_status(): void
    {
        $this->at('2026-09-30 10:00');
        $expiring = $this->makeDocument($this->meEmployee, ['type' => 'IDENTIFICATION', 'expiry_date' => '2026-10-10']);
        $this->makeDocument($this->meEmployee, ['type' => 'CERTIFICATE']);

        $this->actingAsApi($this->me);
        $this->assertSame([$expiring->id], $this->ids($this->getJson('/api/v1/documents?status=expiring')->assertOk()));
        $this->assertSame([$expiring->id], $this->ids($this->getJson('/api/v1/documents?document_type_id='.$this->type('IDENTIFICATION')->id)));
        $this->getJson('/api/v1/documents?status=bogus')->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_user_without_employee_profile_gets_clear_error_for_self_service(): void
    {
        $user = User::query()->create(['name' => 'Ops', 'email' => 'ops@test.example', 'password' => 'password', 'is_active' => true]);
        $user->assignRole('employee');

        $this->actingAsApi($user)->getJson('/api/v1/documents')->assertForbidden()->assertJsonPath('error.code', 'NO_EMPLOYEE_PROFILE');
    }
}
