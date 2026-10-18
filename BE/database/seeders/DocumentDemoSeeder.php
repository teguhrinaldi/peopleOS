<?php

namespace Database\Seeders;

use App\Enums\DocumentStatus;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\User;
use App\Services\Documents\DocumentService;
use App\Services\Documents\DocumentStatusCalculator;
use App\Services\Documents\DocumentStorage;
use App\Services\Documents\DocumentTypeService;
use App\Support\CompanyContext;
use App\Support\Settings;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Demo documents for the DemoSeeder company. Every file is a REAL small PDF (dompdf) written to the
 * private documents disk, so downloads work end to end. Dates are relative to "today" at seed time,
 * so there are always EXPIRING (within 30 days), EXPIRED and one ARCHIVED document.
 *
 * Michael Carter (employee@peopleos.test) has a visible contract, a visible ID reference that is
 * expiring, and an HR-only note document he must never see.
 *
 * Idempotent: a document with the same owner and title is never created twice.
 */
class DocumentDemoSeeder extends Seeder
{
    private Company $company;

    private CarbonImmutable $today;

    /** @var array<string, DocumentType> */
    private array $types = [];

    /** @var array<string, Employee> */
    private array $employees = [];

    private ?User $uploader = null;

    private int $created = 0;

    public function run(): void
    {
        mt_srand(4242); // deterministic demo data

        $context = app(CompanyContext::class);
        $context->forget();

        $company = Company::query()->where('name', 'PeopleOS Demo Company')->first() ?? Company::query()->orderBy('id')->first();
        if (! $company) {
            $this->command?->warn('DocumentDemoSeeder: run DemoSeeder first (no company).');

            return;
        }

        $this->company = $company;
        $this->today = $context->today();

        app(DocumentTypeService::class)->installDefaults();
        $this->types = DocumentType::query()->where('company_id', $company->id)->get()->keyBy('code')->all();
        $this->employees = Employee::withTrashed()->where('company_id', $company->id)->get()->keyBy('employee_number')->all();
        $this->uploader = User::query()->where('email', 'hr@peopleos.test')->first()
            ?? User::query()->where('email', 'admin@peopleos.test')->first();

        $this->employeeDocuments();
        $this->companyDocuments();

        // Bring statuses in line and create the expiry notifications a live system would have sent.
        $result = app(DocumentService::class)->refreshStatuses();

        $this->command?->info("Demo documents created: {$this->created} · expiry notifications: {$result['notified']}");
    }

    private function employeeDocuments(): void
    {
        $contract = 'Employment agreement setting out position, working hours, remuneration and confidentiality obligations.';

        // Employment contracts for most employees. Permanent staff: no expiry; fixed-term: expiry date.
        foreach ([
            'EMP-0001' => null, 'EMP-0008' => null, 'EMP-0009' => null, 'EMP-0010' => null, 'EMP-0011' => null,
            'EMP-0012' => null, 'EMP-0013' => 75, 'EMP-0014' => null, 'EMP-0015' => null, 'EMP-0016' => null,
            'EMP-0017' => null, 'EMP-0018' => null, 'EMP-0019' => null, 'EMP-0020' => 21, 'EMP-0021' => null,
            'EMP-0023' => null,
        ] as $number => $expiresInDays) {
            $employee = $this->employees[$number] ?? null;
            if (! $employee) {
                continue;
            }
            $title = $expiresInDays === null ? 'Employment Contract' : 'Fixed-Term Employment Contract';
            $this->make($number, 'EMPLOYMENT_CONTRACT', $title, [
                'expiry' => $expiresInDays === null ? null : $this->today->addDays($expiresInDays),
                'visible' => true,
                'uploaded' => CarbonImmutable::parse($employee->join_date)->subDays(3),
                'body' => [$contract, 'Signed by both parties. A copy is available to the employee through PeopleOS.'],
            ]);
        }

        // Olivia's previous fixed-term contract: superseded and archived.
        $this->make('EMP-0013', 'EMPLOYMENT_CONTRACT', 'Fixed-Term Employment Contract (2024-2025)', [
            'expiry' => CarbonImmutable::parse('2025-02-04'),
            'visible' => true,
            'uploaded' => CarbonImmutable::parse('2024-02-01'),
            'archived' => CarbonImmutable::parse('2025-02-05'),
            'archive_reason' => 'Superseded by the renewed contract.',
            'body' => [$contract, 'First fixed-term period. Replaced by the renewed agreement.'],
        ]);

        // Identification references (expiry required). Offsets are days from today.
        foreach ([
            'EMP-0012' => ['National ID Card (reference copy)', 12],
            'EMP-0008' => ['National ID Card (reference copy)', 410],
            'EMP-0010' => ['Passport (reference copy)', 530],
            'EMP-0014' => ['Passport (reference copy)', 905],
            'EMP-0015' => ['National ID Card (reference copy)', 640],
            'EMP-0017' => ['National ID Card (reference copy)', 18],
            'EMP-0019' => ['National ID Card (reference copy)', -9],
            'EMP-0021' => ['Passport (reference copy)', 300],
        ] as $number => [$title, $days]) {
            $this->make($number, 'IDENTIFICATION', $title, [
                'expiry' => $this->today->addDays($days),
                'visible' => true,
                'uploaded' => $this->today->subDays(mt_rand(60, 400)),
                'body' => ['Reference copy kept for employment records. The original remains with the employee.', 'Identity numbers are intentionally not reproduced in this demo document.'],
            ]);
        }

        // Certificates.
        foreach ([
            'EMP-0016' => ['Cloud Operations Professional Certificate', 220, true],
            'EMP-0015' => ['First Aid Certificate', -40, true],
            'EMP-0009' => ['Certified HR Professional', null, true],
            'EMP-0021' => ['Warehouse Safety Training Certificate', 26, true],
            'EMP-0011' => ['Chartered Accountant Membership', 150, true],
            'EMP-0012' => ['Digital Marketing Fundamentals Certificate', null, true],
        ] as $number => [$title, $days, $visible]) {
            $this->make($number, 'CERTIFICATE', $title, [
                'expiry' => $days === null ? null : $this->today->addDays($days),
                'visible' => $visible,
                'uploaded' => $this->today->subDays(mt_rand(30, 500)),
                'body' => ['Certificate of successful completion issued to the employee named above.', 'Verified by Human Resources against the issuing body.'],
            ]);
        }

        // HR-only notes: never visible to the employee they concern.
        foreach ([
            'EMP-0012' => ['Reference Check Notes', 'Notes from pre-employment reference checks. Internal to Human Resources.'],
            'EMP-0020' => ['Probation Review Notes', 'Probation review summary and agreed development points. Internal to Human Resources.'],
            'EMP-0022' => ['Leave of Absence Approval', 'Approval and conditions of an extended unpaid leave of absence. Internal to Human Resources.'],
        ] as $number => [$title, $text]) {
            $this->make($number, 'OTHER', $title, [
                'expiry' => null,
                'visible' => false,
                'uploaded' => $this->today->subDays(mt_rand(20, 200)),
                'notes' => 'HR only. Do not share with the employee.',
                'body' => [$text],
            ]);
        }
    }

    private function companyDocuments(): void
    {
        $year = $this->today->year;

        foreach ([
            ['Employee Handbook', null, true, 'Our ways of working: working hours, attendance, leave, benefits, conduct and where to get help.'],
            ['Code of Conduct', null, true, 'The standards of behaviour we expect from everyone, how to raise a concern and how concerns are handled.'],
            ["Holiday Calendar {$year}", CarbonImmutable::create($year, 12, 31), true, "Public holidays and collective leave days observed by the company in {$year}."],
            ['Company Registration Certificate', $this->today->addDays(27), false, 'Company registration record kept by Human Resources and Finance. Renewal due soon.'],
            ['Office Lease Agreement', $this->today->addDays(420), false, 'Lease agreement for the head office premises. Internal document.'],
        ] as [$title, $expiry, $visible, $text]) {
            $this->make(null, $visible ? 'COMPANY_DOCUMENT' : 'OTHER', $title, [
                'expiry' => $expiry,
                'visible' => $visible,
                'uploaded' => $this->today->subDays(mt_rand(15, 240)),
                'body' => [$text, 'This document applies to all employees of the company unless stated otherwise.'],
            ]);
        }
    }

    /** @param array{expiry: ?CarbonImmutable, visible: bool, uploaded: CarbonImmutable, body: list<string>, archived?: CarbonImmutable, archive_reason?: string, notes?: string} $o */
    private function make(?string $number, string $typeCode, string $title, array $o): void
    {
        $employee = $number !== null ? ($this->employees[$number] ?? null) : null;
        $type = $this->types[$typeCode] ?? null;
        if (($number !== null && ! $employee) || ! $type) {
            return;
        }

        $exists = Document::withTrashed()->where('company_id', $this->company->id)
            ->where('employee_id', $employee?->id)->where('title', $title)->exists();
        if ($exists) {
            return;
        }

        $reference = strtoupper(substr($typeCode, 0, 3)).'-'.$o['uploaded']->format('Ymd').'-'.str_pad((string) mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $facts = array_filter([
            'Issued to' => $employee ? "{$employee->full_name} ({$employee->employee_number})" : 'All employees',
            'Document type' => $type->name,
            'Issue date' => $o['uploaded']->format('d M Y'),
            'Valid until' => $o['expiry']?->format('d M Y') ?? 'No expiry',
            'Visibility' => $o['visible'] ? 'Visible to the employee' : 'Human Resources only',
        ]);

        $pdf = Pdf::loadView('documents.pdf.demo', [
            'company' => $this->company->legal_name ?: $this->company->name,
            'address' => (string) $this->company->address,
            'title' => $title,
            'typeName' => $type->name,
            'reference' => $reference,
            'facts' => $facts,
            'paragraphs' => $o['body'],
        ])->setPaper('a4')->output();

        $stored = app(DocumentStorage::class)->storeContents($pdf, $employee?->id);
        $archived = $o['archived'] ?? null;
        $expiry = $o['expiry']?->toDateString();
        $status = app(DocumentStatusCalculator::class)->resolve(
            $archived !== null, $expiry, $this->today->toDateString(), app(Settings::class)->int('documents.expiring_days'),
        );
        $uploadedAt = $o['uploaded']->setTime(9, mt_rand(0, 59))->utc();

        (new Document)->forceFill([
            'company_id' => $this->company->id,
            'employee_id' => $employee?->id,
            'document_type_id' => $type->id,
            'title' => $title,
            'file_disk' => $stored['disk'],
            'file_path' => $stored['path'],
            'file_extension' => 'pdf',
            'original_name' => str_replace(' ', '_', preg_replace('/[^A-Za-z0-9 ]+/', '', $title) ?? 'document').'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($pdf),
            'sha256' => hash('sha256', $pdf),
            'expiry_date' => $expiry,
            'status' => $status,
            // Archived history needs no reminder; everything else is picked up by refreshStatuses().
            'notified_status' => $status === DocumentStatus::Archived ? DocumentStatus::Expired->value : null,
            'visible_to_employee' => $o['visible'],
            'notes' => $o['notes'] ?? null,
            'uploaded_by' => $this->uploader?->id,
            'uploaded_at' => $uploadedAt,
            'archived_at' => $archived?->setTime(10, 0)->utc(),
            'archived_by' => $archived ? $this->uploader?->id : null,
            'archive_reason' => $o['archive_reason'] ?? null,
            'created_at' => $uploadedAt,
            'updated_at' => $archived?->setTime(10, 0)->utc() ?? $uploadedAt,
        ])->save();

        $this->created++;
    }
}
