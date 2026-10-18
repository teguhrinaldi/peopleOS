<?php

namespace App\Services\Documents;

use App\Exceptions\ApiException;
use App\Models\Document;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The only class that touches document binaries.
 *
 * Paths are {directory}/{employeeId|company}/{40 random chars} with NO extension and nothing taken from
 * the client, so a hostile file name can never influence where (or as what) a file is written.
 */
class DocumentStorage
{
    public function __construct(private readonly FileNameSanitizer $names) {}

    public function diskName(): string
    {
        return (string) config('documents.disk', 'local');
    }

    public function disk(?string $name = null): Filesystem
    {
        return Storage::disk($name ?? $this->diskName());
    }

    /** @return array{disk: string, path: string} */
    public function store(InspectedFile $file, ?int $employeeId): array
    {
        return $this->storeFromPath((string) $file->file->getRealPath(), $employeeId);
    }

    /** Server-generated content (demo data, generated PDFs). Same path rules as uploads. @return array{disk: string, path: string} */
    public function storeContents(string $contents, ?int $employeeId): array
    {
        $path = $this->newPath($employeeId);
        if (! $this->disk()->put($path, $contents)) {
            throw new RuntimeException('Document could not be written to storage.');
        }

        return ['disk' => $this->diskName(), 'path' => $path];
    }

    /** @return array{disk: string, path: string} */
    public function storeFromPath(string $sourcePath, ?int $employeeId): array
    {
        $path = $this->newPath($employeeId);

        $stream = fopen($sourcePath, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Uploaded document could not be read.');
        }

        try {
            if (! $this->disk()->writeStream($path, $stream)) {
                throw new RuntimeException('Document could not be written to storage.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return ['disk' => $this->diskName(), 'path' => $path];
    }

    /** {directory}/{employeeId|company}/{random}: only an integer id and random characters, never client input. */
    public function newPath(?int $employeeId): string
    {
        $directory = trim((string) config('documents.directory', 'documents'), '/');

        return $directory.'/'.($employeeId !== null ? (string) (int) $employeeId : 'company').'/'.Str::random(40);
    }

    /** Compensation when the database write fails after the file was stored. */
    public function discard(string $disk, string $path): void
    {
        $this->disk($disk)->delete($path);
    }

    public function exists(Document $document): bool
    {
        return $this->disk($document->file_disk)->exists($document->file_path);
    }

    /** Stream the file as an attachment. Call only AFTER the policy allowed it. */
    public function download(Document $document): StreamedResponse
    {
        $disk = $this->disk($document->file_disk);
        if (! $disk->exists($document->file_path)) {
            throw ApiException::make('FILE_MISSING', 'The file for this document is not available.', 404);
        }

        $name = $this->names->asciiDownloadName($document->original_name, $document->file_extension);

        return new StreamedResponse(function () use ($disk, $document) {
            $stream = $disk->readStream($document->file_path);
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $document->mime_type,
            'Content-Length' => (string) $disk->size($document->file_path),
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'X-Document-Sha256' => $document->sha256,
        ]);
    }
}
