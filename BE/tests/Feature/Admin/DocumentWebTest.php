<?php

namespace Tests\Feature\Admin;

use App\Models\Activity;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Services\Documents\DocumentService;
use Illuminate\Http\UploadedFile;

class DocumentWebTest extends Batch2TestCase
{
    private Employee $anna;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anna = $this->person('Anna Archive');
    }

    /** @return array<string, Document> */
    private function seedDocs(): array
    {
        $id = $this->type('IDENTIFICATION')->id;

        return [
            'contract' => $this->makeDocument($this->anna, ['title' => 'Signed Contract']),
            'expiring' => $this->makeDocument($this->anna, ['title' => 'Passport copy', 'document_type_id' => $id, 'expiry_date' => '2026-10-10']),
            'expired' => $this->makeDocument($this->anna, ['title' => 'Old permit', 'document_type_id' => $id, 'expiry_date' => '2026-09-01']),
            'company' => $this->makeDocument(null, ['title' => 'Employee Handbook']),
            'archived' => tap($this->makeDocument($this->anna, ['title' => 'Superseded NDA']), fn ($d) => app(DocumentService::class)->archive($this->hr, $d, 'Replaced')),
        ];
    }

    private function upload(array $extra = [], ?UploadedFile $file = null)
    {
        return $this->post(route('admin.documents.store'), array_merge([
            'file' => $file ?? $this->pdf('contract.pdf'), 'employee_id' => $this->anna->id,
            'document_type_id' => $this->type('EMPLOYMENT_CONTRACT')->id, 'title' => 'New contract',
        ], $extra));
    }

    public function test_permissions_and_direct_posts(): void
    {
        $docs = $this->seedDocs();

        $this->signIn('employee');
        $this->get(route('admin.documents'))->assertForbidden();
        $this->get(route('admin.documents.file', $docs['contract']))->assertForbidden();
        $this->signIn('manager');
        $this->get(route('admin.documents'))->assertForbidden();
        $this->upload()->assertForbidden();

        $own = $this->customUser('own-docs', ['admin.access', 'document.view_own']);
        $this->actingAs($own)->get(route('admin.documents'))->assertForbidden();
        $this->get(route('admin.documents.show', $docs['company']))->assertForbidden();

        $viewer = $this->customUser('doc-viewer', ['admin.access', 'document.view']);
        $this->actingAs($viewer);
        $this->get(route('admin.documents'))->assertOk()->assertDontSee('Upload document')->assertDontSee('Document types');
        $this->get(route('admin.documents.show', $docs['contract']))->assertOk()->assertDontSee('Edit details');
        $this->get(route('admin.documents.file', $docs['contract']))->assertOk();
        $this->upload()->assertForbidden();
        $this->put(route('admin.documents.update', $docs['contract']), ['title' => 'x', 'document_type_id' => 1])->assertForbidden();
        $this->post(route('admin.documents.archive', $docs['contract']))->assertForbidden();
        $this->post(route('admin.documents.restore', $docs['archived']))->assertForbidden();
        $this->delete(route('admin.documents.destroy', $docs['contract']))->assertForbidden();
        $this->get(route('admin.documents.types'))->assertForbidden();
        $this->assertNull($docs['contract']->fresh()->archived_at);
    }

    public function test_unknown_and_deleted_documents_are_404(): void
    {
        $docs = $this->seedDocs();
        $this->actingAs($this->hr);
        $this->get('/admin/documents/987654')->assertNotFound();
        $this->get('/admin/documents/987654/file')->assertNotFound();
        $this->put('/admin/documents/987654', ['title' => 'x', 'document_type_id' => 1])->assertNotFound();
        $this->post('/admin/documents/987654/archive')->assertNotFound();

        $this->delete(route('admin.documents.destroy', $docs['company']))->assertRedirect(route('admin.documents'))->assertSessionHas('success');
        $this->get(route('admin.documents.show', $docs['company']))->assertNotFound();
        $this->get(route('admin.documents.file', $docs['company']))->assertNotFound();
        $this->assertTrue(Activity::query()->where('action', 'document.deleted')->exists());
    }

    public function test_list_filters_and_status_badges(): void
    {
        $this->seedDocs();
        $this->actingAs($this->hr);

        $this->get(route('admin.documents'))->assertOk()->assertSee('Signed Contract')->assertSee('Employee Handbook')->assertSee('Expiring soon')->assertSee('Expired')->assertSee('Archived')->assertSee('Active');
        $this->get(route('admin.documents', ['status' => 'EXPIRING']))->assertSee('Passport copy')->assertDontSee('Signed Contract')->assertDontSee('Old permit');
        $this->get(route('admin.documents', ['status' => 'EXPIRED']))->assertSee('Old permit')->assertDontSee('Passport copy');
        $this->get(route('admin.documents', ['status' => 'ARCHIVED']))->assertSee('Superseded NDA')->assertDontSee('Signed Contract');
        $this->get(route('admin.documents', ['scope' => 'company']))->assertSee('Employee Handbook')->assertDontSee('Signed Contract');
        $this->get(route('admin.documents', ['scope' => 'employee']))->assertSee('Signed Contract')->assertDontSee('Employee Handbook');
        $this->get(route('admin.documents', ['employee_id' => $this->anna->id]))->assertSee('Signed Contract')->assertDontSee('Employee Handbook');
        $this->get(route('admin.documents', ['document_type_id' => $this->type('COMPANY_DOCUMENT')->id]))->assertSee('Employee Handbook')->assertDontSee('Passport copy');
        $this->get(route('admin.documents', ['expiring_within_days' => 14]))->assertSee('Passport copy')->assertDontSee('Old permit');
        $this->get(route('admin.documents', ['q' => 'passport']))->assertSee('Passport copy')->assertDontSee('Signed Contract');
        $this->get(route('admin.documents', ['q' => 'zzzz']))->assertSee('No documents match');
        $this->get(route('admin.documents', ['status' => 'BOGUS']))->assertSessionHasErrors('status');
    }

    public function test_upload_succeeds_and_is_audited(): void
    {
        $this->actingAs($this->hr);
        $this->upload(['notes' => 'Signed copy'])->assertSessionHasNoErrors()->assertRedirect();
        $doc = Document::query()->where('title', 'New contract')->firstOrFail();
        $this->assertSame($this->anna->id, $doc->employee_id);
        $this->assertTrue(Activity::query()->where('action', 'document.uploaded')->where('entity_id', $doc->id)->exists());
        $this->assertCount(1, $this->storedFiles());

        $this->get(route('admin.documents.show', $doc))->assertOk()->assertSee('New contract')->assertSee('Audit trail')->assertSee('uploaded a document');

        // From the employee tab the user lands back on that tab.
        $this->upload(['title' => 'Tab upload', 'back_to_employee' => '1'])->assertRedirect(route('admin.employees.show', [$this->anna, 'tab' => 'documents']));
        $this->get(route('admin.employees.show', [$this->anna, 'tab' => 'documents']))->assertOk()->assertSee('Tab upload')->assertSee('Upload document');

        // A company document needs no employee.
        $this->upload(['employee_id' => null, 'document_type_id' => $this->type('COMPANY_DOCUMENT')->id, 'title' => 'Policy'])->assertSessionHasNoErrors();
        $this->assertNull(Document::query()->where('title', 'Policy')->first()->employee_id);
    }

    public function test_upload_validation_errors_are_field_errors(): void
    {
        $this->actingAs($this->hr);
        $before = Document::query()->count();

        $this->upload([], UploadedFile::fake()->create('virus.exe', 10))->assertSessionHasErrors('file');
        $this->upload([], UploadedFile::fake()->createWithContent('fake.pdf', "MZ\x90\x00 not a pdf"))->assertSessionHasErrors('file');
        $this->upload([], UploadedFile::fake()->create('huge.pdf', 20000))->assertSessionHasErrors('file');
        $this->assertStringContainsString('too large', session('errors')->first('file'));
        $this->post(route('admin.documents.store'), ['employee_id' => $this->anna->id, 'document_type_id' => $this->type('EMPLOYMENT_CONTRACT')->id, 'title' => 'No file'])->assertSessionHasErrors('file');
        $this->upload(['title' => ''])->assertSessionHasErrors('title');
        $this->upload(['title' => str_repeat('a', 151)])->assertSessionHasErrors('title');
        $this->upload(['document_type_id' => $this->type('IDENTIFICATION')->id])->assertSessionHasErrors('expiry_date');
        $this->upload(['document_type_id' => $this->type('COMPANY_DOCUMENT')->id])->assertSessionHasErrors('document_type_id');
        $this->upload(['employee_id' => null])->assertSessionHasErrors('document_type_id');
        $this->upload(['document_type_id' => 999999])->assertSessionHasErrors('document_type_id');
        $this->upload(['employee_id' => 999999])->assertSessionHasErrors('employee_id');
        $this->upload(['expiry_date' => 'tomorrow'])->assertSessionHasErrors('expiry_date');

        $this->assertSame($before, Document::query()->count());
        $this->assertSame([], $this->storedFiles());
    }

    public function test_detail_download_and_preview_are_authorized_streams(): void
    {
        $docs = $this->seedDocs();
        $this->actingAs($this->hr);

        $this->get(route('admin.documents.show', $docs['contract']))->assertOk()->assertSee('Signed Contract')->assertSee('Preview')->assertSee('Download')->assertDontSee($docs['contract']->file_path);

        $download = $this->get(route('admin.documents.file', $docs['contract']))->assertOk();
        $this->assertStringContainsString('attachment', (string) $download->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $download->headers->get('X-Content-Type-Options'));
        $this->assertStringStartsWith('%PDF', $download->streamedContent());

        $preview = $this->get(route('admin.documents.file', [$docs['contract'], 'preview' => 1]))->assertOk();
        $this->assertStringContainsString('inline', (string) $preview->headers->get('Content-Disposition'));
        $this->assertSame('application/pdf', $preview->headers->get('Content-Type'));
        $this->assertStringContainsString("default-src 'none'", (string) $preview->headers->get('Content-Security-Policy'));

        // Office files never render inline: the preview parameter falls back to a download.
        $docx = $this->makeDocument($this->anna, ['title' => 'Letter'], $this->docx());
        $this->assertStringContainsString('attachment', (string) $this->get(route('admin.documents.file', [$docx, 'preview' => 1]))->headers->get('Content-Disposition'));
        $this->get(route('admin.documents.show', $docx))->assertDontSee('Preview');
    }

    public function test_edit_archive_restore_delete(): void
    {
        $docs = $this->seedDocs();
        $this->actingAs($this->hr);
        $doc = $docs['contract'];
        $type = $this->type('CERTIFICATE');

        $this->put(route('admin.documents.update', $doc), ['title' => '', 'document_type_id' => $type->id])->assertSessionHasErrors('title');
        $this->put(route('admin.documents.update', $doc), ['title' => 'Renamed', 'document_type_id' => $type->id, 'expiry_date' => '2027-01-01', 'visible_to_employee' => '0', 'notes' => 'n'])->assertSessionHasNoErrors()->assertRedirect();
        $fresh = $doc->fresh();
        $this->assertSame('Renamed', $fresh->title);
        $this->assertFalse($fresh->visible_to_employee);
        $this->assertSame('2027-01-01', $fresh->expiry_date->toDateString());

        $this->put(route('admin.documents.update', $doc), ['title' => 'X', 'document_type_id' => $this->type('COMPANY_DOCUMENT')->id])->assertSessionHasErrors('document_type_id');

        $this->post(route('admin.documents.archive', $doc), ['reason' => 'Superseded'])->assertRedirect()->assertSessionHas('success');
        $this->assertSame('Superseded', $doc->fresh()->archive_reason);
        $this->get(route('admin.documents.show', $doc))->assertSee('Archived')->assertSee('Superseded')->assertSee('Restore')->assertDontSee('Edit details');
        $this->post(route('admin.documents.archive', $doc))->assertRedirect()->assertSessionHas('error');
        $this->put(route('admin.documents.update', $doc), ['title' => 'Nope', 'document_type_id' => $type->id])->assertRedirect()->assertSessionHas('error');
        $this->post(route('admin.documents.restore', $doc))->assertRedirect()->assertSessionHas('success');
        $this->assertNull($doc->fresh()->archived_at);
        $this->post(route('admin.documents.restore', $doc))->assertRedirect()->assertSessionHas('error');

        foreach (['document.updated', 'document.archived', 'document.restored'] as $action) {
            $this->assertTrue(Activity::query()->where('action', $action)->exists(), $action);
        }
    }

    public function test_document_types_management(): void
    {
        $this->actingAs($this->hr);
        $this->get(route('admin.documents.types'))->assertOk()->assertSee('Employment Contract')->assertSee('Company Document');

        $this->post(route('admin.documents.types.store'), ['name' => 'Visa', 'requires_expiry' => '1', 'allow_employee_documents' => '1', 'allow_company_documents' => '0', 'default_visible_to_employee' => '1', 'is_active' => '1'])->assertSessionHasNoErrors();
        $visa = DocumentType::query()->where('code', 'VISA')->firstOrFail();
        $this->assertTrue($visa->requires_expiry);
        $this->post(route('admin.documents.types.store'), ['name' => 'Visa', 'allow_employee_documents' => '1'])->assertSessionHasErrors('name');
        $this->post(route('admin.documents.types.store'), ['name' => '', 'allow_employee_documents' => '1'])->assertSessionHasErrors('name');
        $this->post(route('admin.documents.types.store'), ['name' => 'Nowhere', 'allow_employee_documents' => '0', 'allow_company_documents' => '0'])->assertSessionHasErrors('allow_employee_documents');

        $this->put(route('admin.documents.types.update', $visa), ['name' => 'Work visa', 'allow_employee_documents' => '1', 'is_active' => '0'])->assertSessionHasNoErrors();
        $this->assertSame('Work visa', $visa->fresh()->name);
        $this->assertFalse($visa->fresh()->is_active);
        $this->upload(['document_type_id' => $visa->id, 'expiry_date' => '2027-01-01'])->assertSessionHasErrors('document_type_id');
        $this->assertTrue(Activity::query()->where('action', 'document_type.created')->exists());
        $this->assertTrue(Activity::query()->where('action', 'document_type.updated')->exists());
        $this->put('/admin/documents/types/987654', ['name' => 'x', 'allow_employee_documents' => '1'])->assertNotFound();
    }

    public function test_pages_escape_user_content(): void
    {
        $xss = '<script>alert(1)</script>';
        $doc = $this->makeDocument($this->anna, ['title' => $xss, 'notes' => $xss]);
        $this->actingAs($this->hr);
        $this->post(route('admin.documents.types.store'), ['name' => $xss, 'description' => $xss, 'allow_employee_documents' => '1']);

        foreach ([route('admin.documents'), route('admin.documents.show', $doc), route('admin.documents.types'), route('admin.employees.show', [$this->anna, 'tab' => 'documents']), route('admin.reports.show', 'documents.expiring')] as $url) {
            $this->assertStringNotContainsString($xss, $this->get($url)->assertOk()->getContent(), $url);
        }
    }

    public function test_dashboard_expiring_documents_tile_matches_the_data(): void
    {
        $this->seedDocs();
        $this->actingAs($this->hr)->get(route('admin.dashboard'))->assertOk()->assertSee('Expiring documents')->assertSee('1 expired')->assertSee('within 30 days');
        $this->get(route('admin.dashboard'))->assertSee('status=EXPIRING', false)->assertDontSee('coming soon');
    }
}
