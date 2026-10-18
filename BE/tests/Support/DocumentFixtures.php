<?php

namespace Tests\Support;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Documents\DocumentService;
use App\Services\Documents\DocumentTypeService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/** Document helpers on top of WorkplaceFixtures. Files go to a faked private `local` disk. */
trait DocumentFixtures
{
    use WorkplaceFixtures;

    protected User $hr;

    protected function setUpDocuments(): void
    {
        $this->setUpWorkplace();
        Storage::fake('local');
        app(DocumentTypeService::class)->installDefaults();
        [$this->hr] = $this->makeStaff('hr');
    }

    protected function type(string $code): DocumentType
    {
        return DocumentType::query()->where('code', $code)->firstOrFail();
    }

    protected function pdfBytes(string $marker = 'demo'): string
    {
        return "%PDF-1.4\n% {$marker}\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    }

    protected function pdf(string $name = 'contract.pdf', ?string $bytes = null): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes ?? $this->pdfBytes());
    }

    /** @param array<string, string> $entries */
    protected function zipUpload(string $name, array $entries): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'doczip');
        @unlink($path);
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        foreach ($entries as $entry => $content) {
            $zip->addFromString($entry, $content);
        }
        $zip->close();

        return new UploadedFile($path, $name, null, null, true);
    }

    protected function docx(string $name = 'letter.docx'): UploadedFile
    {
        return $this->zipUpload($name, [
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
            '_rels/.rels' => '<Relationships/>',
            'word/document.xml' => '<w:document/>',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    protected function makeDocument(?Employee $employee, array $overrides = [], ?UploadedFile $file = null): Document
    {
        $code = $overrides['type'] ?? ($employee ? 'EMPLOYMENT_CONTRACT' : 'COMPANY_DOCUMENT');
        unset($overrides['type']);

        return app(DocumentService::class)->upload($this->hr, $file ?? $this->pdf(), array_merge([
            'employee_id' => $employee?->id,
            'document_type_id' => $this->type($code)->id,
            'title' => 'Doc '.uniqid(),
            'expiry_date' => null,
            'visible_to_employee' => true,
            'notes' => null,
        ], $overrides));
    }

    /** A login whose role grants no document permission at all. */
    protected function makeNoDocumentStaff(): array
    {
        $role = Role::query()->firstOrCreate(['slug' => 'kiosk'], ['name' => 'Kiosk', 'description' => 'Attendance only', 'is_system' => false]);
        $role->permissions()->sync(Permission::query()->whereIn('slug', ['profile.view', 'attendance.self'])->pluck('id'));

        return $this->makeStaff('kiosk');
    }

    /** @return list<string> */
    protected function storedFiles(): array
    {
        return Storage::disk('local')->allFiles();
    }
}
