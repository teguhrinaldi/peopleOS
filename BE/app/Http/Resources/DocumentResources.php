<?php

namespace App\Http\Resources;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use App\Policies\DocumentPolicy;
use App\Services\Documents\DocumentService;
use App\Support\Fmt;

/**
 * Plain array mappers for documents. The storage disk/path are never part of any output:
 * the file is only reachable through `download_url`, which runs the policy again.
 */
final class DocumentResources
{
    /** @return array<string, mixed> */
    public static function document(Document $d, User $viewer): array
    {
        $service = app(DocumentService::class);
        $hr = app(DocumentPolicy::class)->viewAll($viewer);

        $out = [
            'id' => $d->id,
            'title' => $d->title,
            'document_type' => $d->relationLoaded('type') && $d->type ? ['id' => $d->type->id, 'code' => $d->type->code, 'name' => $d->type->name] : ['id' => $d->document_type_id],
            'employee' => $d->employee_id === null ? null : ($d->relationLoaded('employee') && $d->employee
                ? ['id' => $d->employee->id, 'full_name' => $d->employee->full_name, 'employee_number' => $d->employee->employee_number]
                : ['id' => $d->employee_id]),
            'is_company_document' => $d->employee_id === null,
            'status' => $service->statusFor($d)->value,
            'expiry_date' => Fmt::date($d->expiry_date),
            'days_until_expiry' => $service->daysUntilExpiry($d),
            'file' => [
                'name' => $d->original_name,
                'extension' => $d->file_extension,
                'mime_type' => $d->mime_type,
                'size_bytes' => $d->size_bytes,
                'sha256' => $d->sha256,
            ],
            'download_url' => '/api/v1/documents/'.$d->id.'/download',
            'uploaded_at' => Fmt::iso($d->uploaded_at),
            'updated_at' => Fmt::iso($d->updated_at),
        ];

        if ($hr) {
            $out += [
                'visible_to_employee' => $d->visible_to_employee,
                'notes' => $d->notes,
                'uploaded_by' => $d->relationLoaded('uploader') && $d->uploader ? ['id' => $d->uploader->id, 'name' => $d->uploader->name] : null,
                'archived_at' => Fmt::iso($d->archived_at),
                'archived_by' => $d->relationLoaded('archiver') && $d->archiver ? ['id' => $d->archiver->id, 'name' => $d->archiver->name] : null,
                'archive_reason' => $d->archive_reason,
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function type(DocumentType $t, bool $full = false): array
    {
        $out = [
            'id' => $t->id,
            'code' => $t->code,
            'name' => $t->name,
            'description' => $t->description,
            'requires_expiry' => $t->requires_expiry,
        ];

        if ($full) {
            $out += [
                'default_visible_to_employee' => $t->default_visible_to_employee,
                'allow_employee_documents' => $t->allow_employee_documents,
                'allow_company_documents' => $t->allow_company_documents,
                'is_active' => $t->is_active,
                'sort_order' => $t->sort_order,
            ];
        }

        return $out;
    }

    /** @return list<string> */
    public static function with(): array
    {
        return ['type', 'employee', 'uploader', 'archiver'];
    }
}
