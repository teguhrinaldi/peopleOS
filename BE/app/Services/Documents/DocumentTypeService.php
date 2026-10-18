<?php

namespace App\Services\Documents;

use App\Exceptions\ApiException;
use App\Models\DocumentType;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The configurable catalogue of document types. Types are never hard-deleted once used: retire them
 * with is_active = false (existing documents keep their type; new uploads cannot use it).
 */
class DocumentTypeService
{
    /** Default catalogue installed by DocumentTypeSeeder. [code, name, requires_expiry, visible, employee, company, description] */
    public const DEFAULTS = [
        ['EMPLOYMENT_CONTRACT', 'Employment Contract', false, true, true, false, 'Signed employment agreement and amendments.'],
        ['IDENTIFICATION', 'Identification Reference', true, true, true, false, 'Reference copy of an identity document (national ID, passport, work permit).'],
        ['CERTIFICATE', 'Certificate', false, true, true, false, 'Training, professional or safety certificates.'],
        ['PAYSLIP', 'Payslip', false, true, true, false, 'Payslip PDFs (produced by the payroll module).'],
        ['COMPANY_DOCUMENT', 'Company Document', false, true, false, true, 'Policies, handbooks and calendars for everyone.'],
        ['OTHER', 'Other', false, false, true, true, 'Anything else. HR-only by default.'],
    ];

    public function __construct(
        private readonly CompanyContext $context,
        private readonly ActivityLogger $activity,
    ) {}

    /** @return Collection<int, DocumentType> */
    public function list(bool $includeInactive = false): Collection
    {
        return DocumentType::query()
            ->where('company_id', $this->context->id())
            ->when(! $includeInactive, fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')->orderBy('name')
            ->get();
    }

    /** Idempotent: creates missing default types, never overwrites a type an administrator edited. */
    public function installDefaults(): int
    {
        $created = 0;
        foreach (self::DEFAULTS as $i => [$code, $name, $expiry, $visible, $employee, $company, $description]) {
            $type = DocumentType::query()->firstOrCreate(
                ['company_id' => $this->context->id(), 'code' => $code],
                [
                    'name' => $name, 'description' => $description, 'requires_expiry' => $expiry,
                    'default_visible_to_employee' => $visible, 'allow_employee_documents' => $employee,
                    'allow_company_documents' => $company, 'is_active' => true, 'sort_order' => ($i + 1) * 10,
                ],
            );
            $created += $type->wasRecentlyCreated ? 1 : 0;
        }

        return $created;
    }

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): DocumentType
    {
        $this->assertManager($actor);
        $code = $this->code($data['code'] ?? $data['name'] ?? '');

        try {
            return DB::transaction(function () use ($actor, $data, $code) {
                $type = new DocumentType(['company_id' => $this->context->id(), 'code' => $code]);
                $this->fill($type, $data + [
                    'requires_expiry' => false, 'default_visible_to_employee' => true,
                    'allow_employee_documents' => true, 'allow_company_documents' => false, 'is_active' => true,
                    'sort_order' => ((int) DocumentType::query()->where('company_id', $this->context->id())->max('sort_order')) + 10,
                ]);
                $type->save();

                $this->activity->log('document_type.created', $type, null, $type->only($type->getFillable()), "Document type \"{$type->name}\"", $actor);

                return $type;
            });
        } catch (UniqueConstraintViolationException) {
            throw ApiException::make('DOCUMENT_TYPE_CODE_TAKEN', 'A document type with this code already exists.', 409, ['code' => ['Already in use.']]);
        }
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, DocumentType $type, array $data): DocumentType
    {
        $this->assertManager($actor);

        return DB::transaction(function () use ($actor, $type, $data) {
            $locked = DocumentType::query()->where('company_id', $this->context->id())->whereKey($type->id)->lockForUpdate()->firstOrFail();
            $this->fill($locked, $data);

            if (! $locked->isDirty()) {
                return $locked;
            }

            [$old, $new] = $this->activity->diff($locked);
            $locked->save();
            $this->activity->log('document_type.updated', $locked, $old, $new, "Document type \"{$locked->name}\"", $actor);

            return $locked;
        });
    }

    /** @param array<string, mixed> $data */
    private function fill(DocumentType $type, array $data): void
    {
        foreach (['name', 'description', 'sort_order'] as $key) {
            if (array_key_exists($key, $data)) {
                $type->{$key} = $key === 'sort_order' ? (int) $data[$key] : ($data[$key] === null ? null : trim((string) $data[$key]));
            }
        }
        foreach (['requires_expiry', 'default_visible_to_employee', 'allow_employee_documents', 'allow_company_documents', 'is_active'] as $flag) {
            if (array_key_exists($flag, $data) && $data[$flag] !== null) {
                $type->{$flag} = (bool) $data[$flag];
            }
        }

        if (trim((string) $type->name) === '') {
            throw ValidationException::withMessages(['name' => 'The name is required.']);
        }
        if (! $type->allow_employee_documents && ! $type->allow_company_documents) {
            throw ValidationException::withMessages(['allow_employee_documents' => 'A type must allow employee documents, company documents or both.']);
        }
    }

    private function code(string $value): string
    {
        $code = strtoupper(Str::of($value)->ascii()->replaceMatches('/[^A-Za-z0-9]+/', '_')->trim('_')->limit(40, '')->toString());
        if ($code === '') {
            throw ValidationException::withMessages(['code' => 'The code is required.']);
        }

        return $code;
    }

    private function assertManager(User $actor): void
    {
        if (! $actor->hasPermission('document.manage')) {
            throw ApiException::make('FORBIDDEN', 'You do not have permission to perform this action.', 403);
        }
    }
}
