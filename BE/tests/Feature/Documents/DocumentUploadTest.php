<?php

namespace Tests\Feature\Documents;

use App\Models\Document;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DocumentFixtures;
use Tests\TestCase;

/** Upload validation: content-based MIME checks, malicious files, path traversal, type rules. */
class DocumentUploadTest extends TestCase
{
    use DocumentFixtures, RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
        [, $this->employee] = $this->makeStaff('employee');
    }

    /** @param array<string, mixed> $extra */
    private function upload(UploadedFile $file, array $extra = [], ?User $as = null)
    {
        return $this->actingAsApi($as ?? $this->hr)->post('/api/v1/documents', array_merge([
            'file' => $file,
            'employee_id' => $this->employee->id,
            'document_type_id' => $this->type('EMPLOYMENT_CONTRACT')->id,
            'title' => 'Employment Contract',
        ], $extra), ['Accept' => 'application/json']);
    }

    public function test_hr_uploads_a_pdf_and_only_metadata_is_stored(): void
    {
        $bytes = $this->pdfBytes('upload');
        $res = $this->upload($this->pdf('Contract 2026.pdf', $bytes), ['notes' => 'Signed copy'])->assertCreated()
            ->assertJsonPath('data.title', 'Employment Contract')
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.visible_to_employee', true) // type default
            ->assertJsonPath('data.file.name', 'Contract 2026.pdf')
            ->assertJsonPath('data.file.mime_type', 'application/pdf')
            ->assertJsonPath('data.file.sha256', hash('sha256', $bytes))
            ->assertJsonPath('data.employee.id', $this->employee->id);

        $this->assertStringNotContainsString('documents/', $res->getContent());
        $doc = Document::query()->findOrFail($res->json('data.id'));
        $this->assertMatchesRegularExpression('#^documents/'.$this->employee->id.'/[A-Za-z0-9]{40}$#', $doc->file_path);
        $this->assertSame([$doc->file_path], $this->storedFiles());
        $this->assertSame(strlen($bytes), $doc->size_bytes);
        $this->assertSame($this->hr->id, $doc->uploaded_by);
    }

    public function test_images_and_office_files_are_accepted(): void
    {
        $this->upload(UploadedFile::fake()->image('scan.png', 50, 50))->assertCreated()->assertJsonPath('data.file.mime_type', 'image/png');
        $this->upload(UploadedFile::fake()->image('scan.JPG', 50, 50))->assertCreated()->assertJsonPath('data.file.extension', 'jpg');
        $this->upload($this->docx())->assertCreated()->assertJsonPath('data.file.extension', 'docx');
        $this->upload($this->zipUpload('sheet.xlsx', [
            '[Content_Types].xml' => '<Types/>', '_rels/.rels' => '<x/>', 'xl/workbook.xml' => '<workbook/>',
        ]))->assertCreated()->assertJsonPath('data.file.extension', 'xlsx');
        $this->assertCount(4, $this->storedFiles());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function maliciousFiles(): array
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

        return [
            'php script renamed .pdf' => ['invoice.pdf', "<?php system(\$_GET['c']); ?>"],
            'double extension .pdf.php' => ['invoice.pdf.php', "%PDF-1.4\n%%EOF"],
            'php hidden before .pdf' => ['invoice.php.pdf', "%PDF-1.4\n%%EOF"],
            'svg' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'svg renamed png' => ['logo.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'html' => ['page.html', '<!DOCTYPE html><html><script>alert(1)</script></html>'],
            'html renamed pdf' => ['page.pdf', '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>'],
            'windows executable' => ['setup.exe', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff\x00\x00".str_repeat("\0", 200)],
            'executable renamed pdf' => ['setup.pdf', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff\x00\x00".str_repeat("\0", 200)],
            'png content with pdf extension' => ['photo.pdf', $png],
            'pdf content with png extension' => ['photo.png', "%PDF-1.4\n%%EOF"],
            'pdf polyglot with php payload' => ['poly.pdf', "%PDF-1.4\n<?php echo 1; ?>\n%%EOF"],
            'plain text' => ['notes.txt', 'hello'],
            'no extension' => ['contract', "%PDF-1.4\n%%EOF"],
        ];
    }

    #[DataProvider('maliciousFiles')]
    public function test_malicious_uploads_are_rejected_and_nothing_is_written(string $name, string $content): void
    {
        $this->upload(UploadedFile::fake()->createWithContent($name, $content))
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['file']]]);

        $this->assertSame([], $this->storedFiles());
        $this->assertSame(0, Document::withTrashed()->count());
    }

    public function test_zip_disguised_as_docx_and_macro_documents_are_rejected(): void
    {
        $this->upload($this->zipUpload('letter.docx', ['a.txt' => 'x']))->assertUnprocessable();
        $this->upload($this->zipUpload('letter.docx', [
            '[Content_Types].xml' => '<Types/>', 'word/document.xml' => '<w/>', 'word/vbaProject.bin' => 'macro',
        ]))->assertUnprocessable();
        $this->upload($this->docx('sheet.xlsx'))->assertUnprocessable(); // a docx is not an xlsx
        $this->assertSame([], $this->storedFiles());
    }

    public function test_oversized_upload_is_rejected(): void
    {
        config(['documents.max_kb' => 10]);

        $this->upload(UploadedFile::fake()->createWithContent('big.pdf', $this->pdfBytes(str_repeat('x', 12 * 1024))))
            ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertSame([], $this->storedFiles());
    }

    public function test_default_limit_is_ten_megabytes(): void
    {
        $this->assertSame(10240, (int) config('documents.max_kb'));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function traversalNames(): array
    {
        return [
            'unix traversal' => ['../../.env.pdf', 'env.pdf'],
            'windows traversal' => ['..\\x.pdf', 'x.pdf'],
            'absolute path' => ['/var/www/html/shell.pdf', 'shell.pdf'],
            'nested windows path' => ['C:\\Users\\..\\boot.pdf', 'boot.pdf'],
            'quotes and newlines' => ["con\"tr\r\nact.pdf", 'con_tract.pdf'],
        ];
    }

    #[DataProvider('traversalNames')]
    public function test_hostile_names_are_sanitised_and_never_used_in_paths(string $raw, string $expected): void
    {
        $res = $this->upload($this->pdf($raw))->assertCreated()->assertJsonPath('data.file.name', $expected);

        $doc = Document::query()->findOrFail($res->json('data.id'));
        $this->assertMatchesRegularExpression('#^documents/\d+/[A-Za-z0-9]{40}$#', $doc->file_path);
        $this->assertStringNotContainsString('..', $doc->file_path);
        $this->assertFalse(file_exists(base_path('.env.pdf')));
        $this->assertSame([$doc->file_path], $this->storedFiles());
    }

    public function test_null_byte_in_name_is_rejected(): void
    {
        $this->upload($this->pdf("contract.php\0.pdf"))->assertUnprocessable();
        $this->assertSame([], $this->storedFiles());
    }

    public function test_employee_must_exist_in_the_company(): void
    {
        $this->upload($this->pdf(), ['employee_id' => 999999])
            ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['details' => ['employee_id']]]);
        $this->assertSame([], $this->storedFiles());
    }

    public function test_document_type_rules_are_enforced(): void
    {
        // Identification requires an expiry date.
        $this->upload($this->pdf(), ['document_type_id' => $this->type('IDENTIFICATION')->id])
            ->assertUnprocessable()->assertJsonPath('error.code', 'EXPIRY_DATE_REQUIRED');

        // Company Document type cannot be attached to an employee; employee types need an employee.
        $this->upload($this->pdf(), ['document_type_id' => $this->type('COMPANY_DOCUMENT')->id])
            ->assertUnprocessable()->assertJsonPath('error.code', 'DOCUMENT_TYPE_NOT_ALLOWED');
        $this->upload($this->pdf(), ['employee_id' => null])
            ->assertUnprocessable()->assertJsonPath('error.code', 'DOCUMENT_TYPE_NOT_ALLOWED');

        // Retired types cannot be used for new uploads.
        $this->type('CERTIFICATE')->update(['is_active' => false]);
        $this->upload($this->pdf(), ['document_type_id' => $this->type('CERTIFICATE')->id])
            ->assertUnprocessable()->assertJsonPath('error.code', 'DOCUMENT_TYPE_INACTIVE');

        $this->upload($this->pdf(), ['document_type_id' => 999999])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertSame([], $this->storedFiles());
    }

    public function test_company_document_upload_and_visibility_override(): void
    {
        $this->upload($this->pdf('handbook.pdf'), ['employee_id' => null, 'document_type_id' => $this->type('COMPANY_DOCUMENT')->id, 'title' => 'Handbook'])
            ->assertCreated()->assertJsonPath('data.is_company_document', true)->assertJsonPath('data.employee', null);
        $this->assertStringStartsWith('documents/company/', $this->storedFiles()[0]);

        $this->upload($this->pdf(), ['document_type_id' => $this->type('OTHER')->id, 'visible_to_employee' => '1'])
            ->assertCreated()->assertJsonPath('data.visible_to_employee', true);
        $this->upload($this->pdf(), ['visible_to_employee' => '0'])->assertCreated()->assertJsonPath('data.visible_to_employee', false);
    }

    public function test_missing_fields_and_non_manager_uploads(): void
    {
        $this->actingAsApi($this->hr)->postJson('/api/v1/documents', [])->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['file', 'document_type_id', 'title']]]);

        [$employeeUser] = $this->makeStaff('employee');
        $this->upload($this->pdf(), [], $employeeUser)->assertForbidden();
        $this->assertSame([], $this->storedFiles());
    }
}
