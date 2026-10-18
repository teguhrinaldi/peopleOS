<?php

namespace App\Services\Documents;

use App\Exceptions\ApiException;
use Illuminate\Http\UploadedFile;
use ZipArchive;

/**
 * Decides whether an uploaded file may be stored. Nothing is written anywhere by this class.
 *
 * Checks, in order: a real upload, size, a sane name without executable/markup segments, an allowed
 * extension, the MIME type detected from the CONTENT (finfo, never the client header) matching that
 * extension, format-specific structure (image header, OOXML package) and no embedded PHP tags.
 */
class DocumentFileInspector
{
    public function __construct(private readonly FileNameSanitizer $names) {}

    public function inspect(UploadedFile $file): InspectedFile
    {
        if (! $file->isValid()) {
            $this->reject('The file failed to upload.', 'VALIDATION_ERROR');
        }

        $path = (string) $file->getRealPath();
        $size = (int) @filesize($path);
        $maxBytes = $this->maxKb() * 1024;

        if ($size <= 0) {
            $this->reject('The file is empty.', 'VALIDATION_ERROR');
        }
        if ($size > $maxBytes) {
            $this->reject('The file may not be larger than '.$this->maxKb().' KB.', 'VALIDATION_ERROR');
        }

        $raw = (string) $file->getClientOriginalName();
        if (str_contains($raw, "\0") || $this->names->hasDangerousSegment($raw)) {
            $this->reject('This file name is not allowed.');
        }

        $display = $this->names->displayName($raw);
        $extension = $this->names->extension($display);
        $allowed = (array) config('documents.allowed', []);

        if ($extension === '' || ! array_key_exists($extension, $allowed)) {
            $this->reject('Only '.$this->allowedList().' files are allowed.');
        }

        // Sniff from a buffer of the first bytes: finfo::file() fails on some Windows temp paths.
        $head = @file_get_contents($path, false, null, 0, 8192);
        if ($head === false) {
            $this->reject('The file could not be read.', 'VALIDATION_ERROR');
        }
        $detected = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($head);
        if (! in_array($detected, $allowed[$extension], true)) {
            $this->reject('The file content does not match its .'.$extension.' extension.');
        }

        $this->assertStructure($extension, $path);
        $this->assertNoPhpTags($path);

        return new InspectedFile(
            file: $file,
            displayName: $display,
            extension: $extension,
            mimeType: (string) config("documents.canonical_mime.{$extension}", $detected),
            sizeBytes: $size,
            sha256: (string) hash_file('sha256', $path),
        );
    }

    public function maxKb(): int
    {
        return max(1, (int) config('documents.max_kb', 10240));
    }

    /** @return list<string> */
    public function allowedExtensions(): array
    {
        return array_keys((array) config('documents.allowed', []));
    }

    private function assertStructure(string $extension, string $path): void
    {
        match ($extension) {
            'pdf' => $this->assertPdf($path),
            'jpg', 'jpeg' => $this->assertImage($path, IMAGETYPE_JPEG),
            'png' => $this->assertImage($path, IMAGETYPE_PNG),
            'docx', 'xlsx' => $this->assertOfficePackage($extension, $path),
            default => null, // extra formats added in config rely on the MIME + PHP-tag checks only
        };
    }

    private function assertPdf(string $path): void
    {
        $head = (string) file_get_contents($path, false, null, 0, 1024);
        if (! str_contains($head, '%PDF-')) {
            $this->reject('The file is not a valid PDF.');
        }
    }

    private function assertImage(string $path, int $expectedType): void
    {
        $info = @getimagesize($path);
        if ($info === false || $info[2] !== $expectedType) {
            $this->reject('The file is not a valid image.');
        }
    }

    /** OOXML = ZIP with [Content_Types].xml and the part that defines the format; macro parts are refused. */
    private function assertOfficePackage(string $extension, string $path): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->reject('Office files cannot be verified on this server.');
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            $this->reject('The file is not a valid .'.$extension.' document.');
        }

        try {
            $main = $extension === 'docx' ? 'word/document.xml' : 'xl/workbook.xml';
            if ($zip->locateName('[Content_Types].xml') === false || $zip->locateName($main) === false) {
                $this->reject('The file is not a valid .'.$extension.' document.');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = (string) $zip->getNameIndex($i);
                if (str_contains($entry, '..') || str_starts_with($entry, '/') || stripos($entry, 'vbaProject.bin') !== false) {
                    $this->reject('The document contains content that is not allowed.');
                }
            }
        } finally {
            $zip->close();
        }
    }

    /** A PHP payload smuggled inside an otherwise valid file (polyglot) is refused outright. */
    private function assertNoPhpTags(string $path): void
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            $this->reject('The file could not be read.', 'VALIDATION_ERROR');
        }

        $carry = '';
        try {
            while (! feof($handle)) {
                $chunk = $carry.(string) fread($handle, 65536);
                if (stripos($chunk, '<?php') !== false) {
                    $this->reject('The file contains executable code.');
                }
                $carry = substr($chunk, -4);
            }
        } finally {
            fclose($handle);
        }
    }

    private function allowedList(): string
    {
        return implode(', ', array_map('strtoupper', array_values(array_unique(array_map(
            fn (string $e) => $e === 'jpeg' ? 'jpg' : $e,
            $this->allowedExtensions(),
        )))));
    }

    private function reject(string $message, string $code = 'UNSUPPORTED_FILE_TYPE'): never
    {
        throw ApiException::make($code, $message, 422, ['file' => [$message]]);
    }
}
