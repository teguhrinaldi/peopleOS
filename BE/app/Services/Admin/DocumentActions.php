<?php

namespace App\Services\Admin;

use App\Exceptions\ApiException;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use App\Services\Admin\Concerns\TranslatesDomainErrors;
use App\Services\Documents\DocumentService;
use App\Services\Documents\DocumentStorage;
use App\Services\Documents\DocumentTypeService;
use App\Support\CompanyContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Web entry point for document writes and file streaming. Validation of the file itself, type rules, expiry,
 * archive/restore and audit all live in DocumentService; this class maps its errors onto the right form field
 * and gates every stream behind DocumentPolicy.
 */
class DocumentActions
{
    use TranslatesDomainErrors;

    private const FIELDS = [
        'UNSUPPORTED_FILE_TYPE' => 'file', 'FILE_TOO_LARGE' => 'file', 'EXPIRY_DATE_REQUIRED' => 'expiry_date',
        'DOCUMENT_TYPE_NOT_ALLOWED' => 'document_type_id', 'DOCUMENT_TYPE_INACTIVE' => 'document_type_id', 'DOCUMENT_TYPE_CODE_TAKEN' => 'name',
    ];

    public function __construct(
        private readonly DocumentService $documents,
        private readonly DocumentTypeService $types,
        private readonly DocumentStorage $storage,
        private readonly CompanyContext $context,
    ) {}

    /** @param array<string, mixed> $data */
    public function upload(User $actor, UploadedFile $file, array $data): Document
    {
        return $this->domain(fn () => $this->documents->upload($actor, $file, $data), self::FIELDS);
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, Document $document, array $data): Document
    {
        return $this->domain(fn () => $this->documents->update($actor, $document, $data), self::FIELDS);
    }

    public function archive(User $actor, Document $document, ?string $reason): Document
    {
        return $this->documents->archive($actor, $document, $reason);
    }

    public function restore(User $actor, Document $document): Document
    {
        return $this->documents->restore($actor, $document);
    }

    public function delete(User $actor, Document $document): void
    {
        $this->documents->delete($actor, $document);
    }

    /** @param array<string, mixed> $data */
    public function saveType(User $actor, ?DocumentType $type, array $data): DocumentType
    {
        return $this->domain(fn () => $type ? $this->types->update($actor, $type, $data) : $this->types->create($actor, $data), self::FIELDS);
    }

    /**
     * Stream the file after DocumentPolicy::download. Previews are inline only for PDF/PNG/JPEG; the content type
     * is the canonical one stored at upload (never client-declared) with nosniff and a locked-down CSP.
     */
    public function stream(User $viewer, Document $document, bool $inline): StreamedResponse
    {
        Gate::forUser($viewer)->authorize('download', $document);

        if (! $inline || ! in_array($document->file_extension, DocumentDesk::PREVIEWABLE, true)) {
            return $this->storage->download($document);
        }

        $disk = $this->storage->disk($document->file_disk);
        if (! $disk->exists($document->file_path)) {
            throw ApiException::make('FILE_MISSING', 'The file for this document is not available.', 404);
        }

        return new StreamedResponse(function () use ($disk, $document) {
            $stream = $disk->readStream($document->file_path);
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $document->mime_type,
            'Content-Length' => (string) $disk->size($document->file_path),
            'Content-Disposition' => 'inline; filename="document.'.$document->file_extension.'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; frame-ancestors 'self'",
        ]);
    }

    public function companyId(): int
    {
        return $this->context->id();
    }
}
