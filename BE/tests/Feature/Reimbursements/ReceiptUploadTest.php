<?php

namespace Tests\Feature\Reimbursements;

use App\Models\ExpenseRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\ReimbursementFixtures;
use Tests\TestCase;

/** Receipt upload hardening and authorized streaming (private disk, no client-controlled paths). */
class ReceiptUploadTest extends TestCase
{
    use RefreshDatabase, ReimbursementFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReimbursements();
        $this->at('2026-10-10 10:00');
        $this->actingAsApi($this->employeeUser);
    }

    private function upload(UploadedFile $file): TestResponse
    {
        return $this->post('/api/v1/reimbursements', $this->payload() + ['receipt' => $file], ['Accept' => 'application/json']);
    }

    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
    }

    public function test_valid_pdf_png_and_jpg_are_stored_privately_under_a_random_path(): void
    {
        $this->upload($this->receipt('Taxi receipt.pdf'))->assertCreated()->assertJsonPath('data.receipt.mime_type', 'application/pdf');
        $this->upload(UploadedFile::fake()->createWithContent('parking.png', $this->png()))->assertCreated()->assertJsonPath('data.receipt.extension', 'png');
        $this->upload(UploadedFile::fake()->image('meal.jpg', 20, 20))->assertCreated()->assertJsonPath('data.receipt.mime_type', 'image/jpeg');

        $files = Storage::disk('local')->allFiles();
        $this->assertCount(3, $files);
        foreach ($files as $path) {
            $this->assertMatchesRegularExpression('#^receipts/'.$this->employee->id.'/[A-Za-z0-9]{40}$#', $path);
        }
    }

    public function test_hostile_files_are_refused_before_anything_is_written(): void
    {
        $cases = [
            'php disguised as pdf' => UploadedFile::fake()->createWithContent('receipt.pdf', "<?php system(\$_GET['c']); ?>"),
            'pdf with embedded php' => UploadedFile::fake()->createWithContent('receipt.pdf', $this->pdfBytes().'<?php echo 1; ?>'),
            'double extension .php.pdf' => $this->receipt('receipt.php.pdf'),
            'trailing .php' => UploadedFile::fake()->createWithContent('receipt.pdf.php', $this->pdfBytes()),
            'svg' => UploadedFile::fake()->createWithContent('receipt.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'),
            'html' => UploadedFile::fake()->createWithContent('receipt.html', '<html><script>alert(1)</script></html>'),
            'exe renamed to png' => UploadedFile::fake()->createWithContent('receipt.png', "MZ\x90\x00".str_repeat("\x00", 200)),
            'png named jpg' => UploadedFile::fake()->createWithContent('receipt.jpg', $this->png()),
            'docx is not a receipt type' => UploadedFile::fake()->createWithContent('receipt.docx', 'PK'),
            'empty' => UploadedFile::fake()->createWithContent('receipt.pdf', ''),
            'no extension' => UploadedFile::fake()->createWithContent('receipt', $this->pdfBytes()),
        ];

        foreach ($cases as $label => $file) {
            $res = $this->upload($file);
            $this->assertSame(422, $res->status(), "{$label}: ".$res->getContent());
        }

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, ExpenseRequest::query()->count(), 'a refused receipt creates no claim');
    }

    public function test_size_limit_comes_from_the_company_policy(): void
    {
        $this->setPolicy(['max_receipt_kb' => 1]);
        $this->upload($this->receipt('big.pdf', $this->pdfBytes(str_repeat('A', 2048))))->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->upload($this->receipt('small.pdf'))->assertCreated();

        config(['reimbursements.max_kb_ceiling' => 1]);
        $this->setPolicy(['max_receipt_kb' => 5000]);
        $this->getJson('/api/v1/expense-policy')->assertJsonPath('data.effective_max_receipt_kb', 1);
    }

    public function test_traversal_names_never_reach_the_storage_path(): void
    {
        $id = $this->upload($this->receipt('../../../../.env/..\\..\\evil receipt.pdf'))->assertCreated()->json('data.id');

        $claim = ExpenseRequest::query()->findOrFail($id);
        $this->assertSame('evil receipt.pdf', $claim->receipt_original_name);
        $this->assertStringNotContainsString('..', $claim->receipt_path);
        $this->assertStringNotContainsString('evil', $claim->receipt_path);
        $this->assertStringStartsWith('receipts/'.$this->employee->id.'/', $claim->receipt_path);
    }

    public function test_download_is_authorized_streamed_and_never_exposes_the_path(): void
    {
        $bytes = $this->pdfBytes('stream-me');
        $res = $this->upload($this->receipt('Hotel "Bill".pdf', $bytes))->assertCreated();
        $id = $res->json('data.id');
        $this->assertStringNotContainsString('receipts/', $res->getContent());
        $this->assertStringNotContainsString('receipt_path', $res->getContent());
        $this->assertStringNotContainsString('receipt_disk', $res->getContent());

        $download = $this->get("/api/v1/reimbursements/{$id}/receipt")->assertOk();
        $this->assertSame($bytes, $download->streamedContent());
        $download->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Type', 'application/pdf');
        $this->assertMatchesRegularExpression('/^attachment; filename="Hotel[A-Za-z0-9 _]*.pdf"$/', (string) $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $download->headers->get('Cache-Control'));

        // Drafts are private: HR cannot fetch the receipt until the claim is submitted.
        $this->actingAsApi($this->hr)->get("/api/v1/reimbursements/{$id}/receipt")->assertNotFound();
        $this->actingAsApi($this->employeeUser)->postJson("/api/v1/reimbursements/{$id}/submit")->assertOk();
        $this->actingAsApi($this->hr)->get("/api/v1/reimbursements/{$id}/receipt")->assertOk();
        $this->actingAsApi($this->manager)->get("/api/v1/reimbursements/{$id}/receipt")->assertOk();
        $this->actingAsApi($this->stranger)->get("/api/v1/reimbursements/{$id}/receipt")->assertNotFound();

        $noReceipt = $this->makeClaim(overrides: ['expense_category_id' => $this->category('PARKING_TOLL')->id, 'submitted_amount' => '10000'], withReceipt: false);
        $this->actingAsApi($this->employeeUser)->get("/api/v1/reimbursements/{$noReceipt->id}/receipt")->assertNotFound();
    }

    public function test_replacing_a_draft_receipt_keeps_the_old_file_and_records_it(): void
    {
        $id = $this->upload($this->receipt('first.pdf', $this->pdfBytes('one')))->json('data.id');
        $oldSha = ExpenseRequest::query()->findOrFail($id)->receipt_sha256;

        $this->post("/api/v1/reimbursements/{$id}/receipt", ['receipt' => $this->receipt('second.pdf', $this->pdfBytes('two'))], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.receipt.name', 'second.pdf');

        $this->assertCount(2, Storage::disk('local')->allFiles(), 'history is never deleted');
        $event = ExpenseRequest::query()->findOrFail($id)->events()->where('action', 'updated')->firstOrFail();
        $this->assertSame($oldSha, $event->data['replaced_receipt_sha256']);
    }
}
