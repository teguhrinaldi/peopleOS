<?php

namespace App\Services\Reimbursements;

use App\Exceptions\ApiException;
use App\Models\ExpenseRequest;
use App\Services\Documents\FileNameSanitizer;
use App\Services\Documents\InspectedFile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The only class that touches receipt binaries. Paths are {directory}/{employeeId}/{40 random chars}: an integer id plus
 * random characters, never anything from the client, so a hostile file name cannot influence where or as what a file is written.
 */
class ReceiptStorage
{
    public function __construct(private readonly FileNameSanitizer $names) {}

    public function diskName(): string
    {
        return (string) config('reimbursements.disk', 'local');
    }

    public function disk(?string $name = null): Filesystem
    {
        return Storage::disk($name ?? $this->diskName());
    }

    /** @return array{disk: string, path: string} */
    public function store(InspectedFile $file, int $employeeId): array
    {
        $path = $this->newPath($employeeId);
        $stream = fopen((string) $file->file->getRealPath(), 'rb');
        if ($stream === false) {
            throw new RuntimeException('Receipt could not be read.');
        }

        try {
            if (! $this->disk()->writeStream($path, $stream)) {
                throw new RuntimeException('Receipt could not be written to storage.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return ['disk' => $this->diskName(), 'path' => $path];
    }

    /** Server-generated content (demo data). Same path rules as uploads. @return array{disk: string, path: string} */
    public function storeContents(string $contents, int $employeeId): array
    {
        $path = $this->newPath($employeeId);
        if (! $this->disk()->put($path, $contents)) {
            throw new RuntimeException('Receipt could not be written to storage.');
        }

        return ['disk' => $this->diskName(), 'path' => $path];
    }

    public function newPath(int $employeeId): string
    {
        return trim((string) config('reimbursements.directory', 'receipts'), '/').'/'.(int) $employeeId.'/'.Str::random(40);
    }

    /** Compensation when the database write fails after the file was stored. */
    public function discard(string $disk, string $path): void
    {
        $this->disk($disk)->delete($path);
    }

    /** Stream as an attachment. Call only AFTER the policy allowed it. */
    public function download(ExpenseRequest $request): StreamedResponse
    {
        if (! $request->hasReceipt()) {
            throw ApiException::make('NOT_FOUND', 'The requested resource was not found.', 404);
        }
        $disk = $this->disk((string) $request->receipt_disk);
        if (! $disk->exists($request->receipt_path)) {
            throw ApiException::make('FILE_MISSING', 'The receipt file is not available.', 404);
        }

        $name = $this->names->asciiDownloadName((string) $request->receipt_original_name, (string) $request->receipt_extension);

        return new StreamedResponse(function () use ($disk, $request) {
            $stream = $disk->readStream($request->receipt_path);
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => (string) $request->receipt_mime,
            'Content-Length' => (string) $disk->size($request->receipt_path),
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'X-Receipt-Sha256' => (string) $request->receipt_sha256,
        ]);
    }
}
