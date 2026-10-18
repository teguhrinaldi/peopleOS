<?php

namespace App\Services\Documents;

use App\Enums\DocumentStatus;
use App\Exceptions\ApiException;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\CompanyContext;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Business rules for documents, shared by the API and the web admin.
 *
 * Every mutation re-checks DocumentPolicy for the acting user (so no caller can bypass it), runs in a
 * transaction with the row locked, keeps the stored status in sync and writes an audit row that never
 * contains the storage path. Deletion is a soft delete; the file is retained (see docs/documents.md).
 */
class DocumentService
{
    /** Attributes that may appear in audit rows. file_disk / file_path are deliberately absent. */
    private const AUDITED = [
        'title', 'employee_id', 'document_type_id', 'expiry_date', 'status', 'visible_to_employee', 'notes',
        'original_name', 'mime_type', 'size_bytes', 'sha256', 'archived_at', 'archive_reason',
    ];

    public function __construct(
        private readonly DocumentFileInspector $inspector,
        private readonly DocumentStorage $storage,
        private readonly DocumentStatusCalculator $calculator,
        private readonly DocumentNotifier $notifier,
        private readonly CompanyContext $context,
        private readonly Settings $settings,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * @param  array{employee_id?: int|null, document_type_id: int, title: string, expiry_date?: string|null,
     *               visible_to_employee?: bool|null, notes?: string|null}  $data
     */
    public function upload(User $actor, UploadedFile $file, array $data): Document
    {
        Gate::forUser($actor)->authorize('create', Document::class);

        $type = $this->resolveType((int) ($data['document_type_id'] ?? 0));
        $employee = $this->resolveEmployee($data['employee_id'] ?? null);
        $this->assertTypeAllows($type, $employee !== null);

        $expiry = $this->normalizeDate($data['expiry_date'] ?? null);
        $this->assertExpiry($type, $expiry);

        // Content checks run before anything touches the disk.
        $inspected = $this->inspector->inspect($file);

        $stored = null;

        try {
            return DB::transaction(function () use ($actor, $type, $employee, $expiry, $inspected, $data, &$stored) {
                $stored = $this->storage->store($inspected, $employee?->id);

                $title = trim((string) ($data['title'] ?? ''));
                $document = Document::query()->create([
                    'company_id' => $this->context->id(),
                    'employee_id' => $employee?->id,
                    'document_type_id' => $type->id,
                    'title' => $title !== '' ? mb_substr($title, 0, 150) : $inspected->displayName,
                    'file_disk' => $stored['disk'],
                    'file_path' => $stored['path'],
                    'file_extension' => $inspected->extension,
                    'original_name' => $inspected->displayName,
                    'mime_type' => $inspected->mimeType,
                    'size_bytes' => $inspected->sizeBytes,
                    'sha256' => $inspected->sha256,
                    'expiry_date' => $expiry,
                    'status' => $this->derive($expiry),
                    'visible_to_employee' => array_key_exists('visible_to_employee', $data) && $data['visible_to_employee'] !== null
                        ? (bool) $data['visible_to_employee']
                        : $type->default_visible_to_employee,
                    'notes' => $this->nullableText($data['notes'] ?? null),
                    'uploaded_by' => $actor->id,
                    'uploaded_at' => now(),
                ]);

                $this->activity->log('document.uploaded', $document, null, $this->auditValues($document), $this->describe($document), $actor);

                return $document;
            });
        } catch (Throwable $e) {
            if ($stored !== null) {
                $this->storage->discard($stored['disk'], $stored['path']);
            }
            throw $e;
        }
    }

    /**
     * Metadata only; the file itself is immutable (upload a new document and archive the old one).
     *
     * @param  array{title?: string, document_type_id?: int, expiry_date?: string|null,
     *               visible_to_employee?: bool, notes?: string|null}  $data  keys present = fields to change
     */
    public function update(User $actor, Document $document, array $data): Document
    {
        return DB::transaction(function () use ($actor, $document, $data) {
            $locked = $this->lock($document);
            Gate::forUser($actor)->authorize('update', $locked);

            if ($locked->isArchived()) {
                throw ApiException::make('DOCUMENT_ARCHIVED', 'Restore this document before editing it.', 409);
            }

            $type = $locked->type;
            if (array_key_exists('document_type_id', $data) && (int) $data['document_type_id'] !== (int) $locked->document_type_id) {
                $type = $this->resolveType((int) $data['document_type_id']);
                $this->assertTypeAllows($type, $locked->employee_id !== null);
                $locked->document_type_id = $type->id;
            }

            if (array_key_exists('title', $data)) {
                $title = trim((string) $data['title']);
                if ($title === '') {
                    throw ValidationException::withMessages(['title' => 'The title is required.']);
                }
                $locked->title = mb_substr($title, 0, 150);
            }

            if (array_key_exists('expiry_date', $data)) {
                $expiry = $this->normalizeDate($data['expiry_date']);
                if ($expiry !== $locked->expiry_date?->toDateString()) {
                    $locked->expiry_date = $expiry;
                    $locked->notified_status = null; // a new expiry date starts a new reminder cycle
                }
            }
            $this->assertExpiry($type, $locked->expiry_date?->toDateString());

            if (array_key_exists('visible_to_employee', $data) && $data['visible_to_employee'] !== null) {
                $locked->visible_to_employee = (bool) $data['visible_to_employee'];
            }
            if (array_key_exists('notes', $data)) {
                $locked->notes = $this->nullableText($data['notes']);
            }

            $locked->status = $this->derive($locked->expiry_date?->toDateString());

            if (! $locked->isDirty()) {
                return $locked;
            }

            $locked->updated_by = $actor->id;
            [$old, $new] = $this->auditDiff($locked);
            $locked->save();

            $this->activity->log('document.updated', $locked, $old, $new, $this->describe($locked), $actor);

            return $locked;
        });
    }

    public function archive(User $actor, Document $document, ?string $reason = null): Document
    {
        return DB::transaction(function () use ($actor, $document, $reason) {
            $locked = $this->lock($document);
            Gate::forUser($actor)->authorize('archive', $locked);

            if ($locked->isArchived()) {
                throw ApiException::make('DOCUMENT_ALREADY_ARCHIVED', 'This document is already archived.', 409);
            }

            $locked->forceFill([
                'status' => DocumentStatus::Archived,
                'archived_at' => now(),
                'archived_by' => $actor->id,
                'archive_reason' => $this->nullableText($reason, 500),
                'updated_by' => $actor->id,
            ]);
            [$old, $new] = $this->auditDiff($locked);
            $locked->save();

            $this->activity->log('document.archived', $locked, $old, $new, $this->describe($locked), $actor);

            return $locked;
        });
    }

    /** Back to the derived state (ACTIVE / EXPIRING / EXPIRED) for today's date. */
    public function restore(User $actor, Document $document): Document
    {
        return DB::transaction(function () use ($actor, $document) {
            $locked = $this->lock($document);
            Gate::forUser($actor)->authorize('restore', $locked);

            if (! $locked->isArchived()) {
                throw ApiException::make('DOCUMENT_NOT_ARCHIVED', 'Only archived documents can be restored.', 409);
            }

            $locked->forceFill([
                'archived_at' => null,
                'archived_by' => null,
                'archive_reason' => null,
                'status' => $this->derive($locked->expiry_date?->toDateString()),
                'updated_by' => $actor->id,
            ]);
            [$old, $new] = $this->auditDiff($locked);
            $locked->save();

            $this->activity->log('document.restored', $locked, $old, $new, $this->describe($locked), $actor);

            return $locked;
        });
    }

    /** Soft delete. The file stays on the private disk for retention; purging is out of scope. */
    public function delete(User $actor, Document $document): void
    {
        DB::transaction(function () use ($actor, $document) {
            $locked = $this->lock($document);
            Gate::forUser($actor)->authorize('delete', $locked);

            $old = $this->auditValues($locked);
            $locked->forceFill(['deleted_by' => $actor->id])->save();
            $locked->delete();

            $this->activity->log('document.deleted', $locked, $old, null, $this->describe($locked), $actor);
        });
    }

    /**
     * Sync stored statuses with today's date and send expiry notifications. Idempotent and safe to run
     * concurrently: status updates are single conditional UPDATEs that never touch archived or deleted
     * rows; each notification is sent under a row lock, recorded in notified_status and deduplicated.
     *
     * @return array{updated: int, notified: int}
     */
    public function refreshStatuses(): array
    {
        [$today, $horizon] = $this->window();
        $base = fn (): Builder => Document::query()->where('company_id', $this->context->id());
        $stamp = ['updated_at' => now()];

        $updated = $base()->whereNull('archived_at')->where('expiry_date', '<', $today)
            ->where('status', '!=', DocumentStatus::Expired->value)
            ->update(['status' => DocumentStatus::Expired->value] + $stamp);

        $updated += $base()->whereNull('archived_at')->whereBetween('expiry_date', [$today, $horizon])
            ->where('status', '!=', DocumentStatus::Expiring->value)
            ->update(['status' => DocumentStatus::Expiring->value] + $stamp);

        $updated += $base()->whereNull('archived_at')
            ->where(fn (Builder $w) => $w->whereNull('expiry_date')->orWhere('expiry_date', '>', $horizon))
            ->where('status', '!=', DocumentStatus::Active->value)
            ->update(['status' => DocumentStatus::Active->value] + $stamp);

        // Repair only: ARCHIVED is entered exclusively through archive().
        $updated += $base()->whereNotNull('archived_at')->where('status', '!=', DocumentStatus::Archived->value)
            ->update(['status' => DocumentStatus::Archived->value] + $stamp);

        $notified = 0;
        $pending = $base()->whereNull('archived_at')
            ->whereIn('status', [DocumentStatus::Expiring->value, DocumentStatus::Expired->value])
            ->where(fn (Builder $w) => $w->whereNull('notified_status')->orWhereColumn('notified_status', '!=', 'status'))
            ->orderBy('id')
            ->pluck('id');

        foreach ($pending as $id) {
            $notified += DB::transaction(function () use ($id) {
                $document = Document::query()->whereKey($id)->lockForUpdate()->first();

                if (! $document || $document->isArchived() || $document->notified_status === $document->status->value
                    || ! in_array($document->status, [DocumentStatus::Expiring, DocumentStatus::Expired], true)) {
                    return 0; // another run got here first, or the document changed meanwhile
                }

                $sent = $this->notifier->notifyStatus($document, $document->status);
                $document->forceFill(['notified_status' => $document->status->value])->saveQuietly();

                return $sent;
            });
        }

        return ['updated' => $updated, 'notified' => $notified];
    }

    /** Live status for today's date (company timezone); ARCHIVED wins. */
    public function statusFor(Document $document): DocumentStatus
    {
        [$today] = $this->window();

        return $this->calculator->resolve(
            $document->isArchived(),
            $document->expiry_date?->toDateString(),
            $today,
            $this->expiringDays(),
        );
    }

    public function daysUntilExpiry(Document $document): ?int
    {
        return $document->expiry_date
            ? $this->calculator->daysUntilExpiry($document->expiry_date->toDateString(), $this->context->today()->toDateString())
            : null;
    }

    public function expiringDays(): int
    {
        return max(0, $this->settings->int('documents.expiring_days'));
    }

    private function derive(?string $expiry): DocumentStatus
    {
        return $this->calculator->derive($expiry, $this->context->today()->toDateString(), $this->expiringDays());
    }

    /** @return array{0: string, 1: string} */
    private function window(): array
    {
        $today = $this->context->today();

        return [$today->toDateString(), $today->addDays($this->expiringDays())->toDateString()];
    }

    private function lock(Document $document): Document
    {
        $locked = Document::query()->whereKey($document->getKey())->lockForUpdate()->first();

        if (! $locked) {
            throw ApiException::make('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        return $locked;
    }

    private function resolveType(int $id): DocumentType
    {
        $type = DocumentType::query()->where('company_id', $this->context->id())->whereKey($id)->first();

        if (! $type) {
            throw ValidationException::withMessages(['document_type_id' => 'The selected document type does not exist.']);
        }
        if (! $type->is_active) {
            throw ApiException::make('DOCUMENT_TYPE_INACTIVE', 'This document type is no longer in use.', 422, ['document_type_id' => ['This document type is no longer in use.']]);
        }

        return $type;
    }

    /** The client's employee_id is only trusted after proving the employee exists in this company. */
    private function resolveEmployee(mixed $id): ?Employee
    {
        if ($id === null || $id === '') {
            return null;
        }

        $employee = Employee::query()->where('company_id', $this->context->id())->whereKey((int) $id)->first();
        if (! $employee) {
            throw ValidationException::withMessages(['employee_id' => 'The selected employee does not exist.']);
        }

        return $employee;
    }

    private function assertTypeAllows(DocumentType $type, bool $forEmployee): void
    {
        if ($forEmployee && ! $type->allow_employee_documents) {
            throw ApiException::make('DOCUMENT_TYPE_NOT_ALLOWED', "{$type->name} documents cannot be attached to an employee.", 422, ['document_type_id' => ['Not allowed for employee documents.']]);
        }
        if (! $forEmployee && ! $type->allow_company_documents) {
            throw ApiException::make('DOCUMENT_TYPE_NOT_ALLOWED', "{$type->name} documents must belong to an employee.", 422, ['document_type_id' => ['Not allowed for company documents.']]);
        }
    }

    private function assertExpiry(DocumentType $type, ?string $expiry): void
    {
        if ($type->requires_expiry && $expiry === null) {
            throw ApiException::make('EXPIRY_DATE_REQUIRED', "{$type->name} documents need an expiry date.", 422, ['expiry_date' => ['An expiry date is required for this document type.']]);
        }
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return substr((string) $value, 0, 10);
    }

    private function nullableText(mixed $value, int $max = 1000): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    /** @return array<string, mixed> */
    private function auditValues(Document $document): array
    {
        $values = array_intersect_key($document->attributesToArray(), array_flip(self::AUDITED));
        $values['expiry_date'] = $document->expiry_date?->toDateString();

        return $values;
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function auditDiff(Document $document): array
    {
        [$old, $new] = $this->activity->diff($document);
        $allowed = array_flip(self::AUDITED);

        // Raw attribute values: dates arrive as 'Y-m-d H:i:s' strings; expiry is a business DATE.
        $clean = function (array $values) use ($allowed): array {
            $values = array_intersect_key($values, $allowed);
            if (isset($values['expiry_date'])) {
                $values['expiry_date'] = substr((string) $values['expiry_date'], 0, 10);
            }

            return $values;
        };

        return [$clean($old), $clean($new)];
    }

    private function describe(Document $document): string
    {
        return $document->employee_id
            ? "Document \"{$document->title}\" for employee #{$document->employee_id}"
            : "Company document \"{$document->title}\"";
    }
}
