<?php

namespace App\Services\Reimbursements;

use App\Exceptions\ApiException;
use App\Services\Documents\FileNameSanitizer;
use App\Services\Documents\InspectedFile;
use Illuminate\Http\UploadedFile;

/**
 * Decides whether an uploaded receipt may be stored (same hardening as DocumentFileInspector, receipt-specific config):
 * a real upload, size within the policy limit, a name without executable/markup segments, an allowed extension (pdf/jpg/png),
 * a MIME type detected from the CONTENT matching that extension, a valid PDF header / image structure and no embedded PHP tags.
 * Nothing is written anywhere by this class.
 */
class ReceiptFileInspector
{
    public function __construct(private readonly FileNameSanitizer $names, private readonly ExpensePolicyService $policy) {}

    public function inspect(UploadedFile $file): InspectedFile
    {
        if (! $file->isValid()) {
            $this->reject('The receipt failed to upload.', 'VALIDATION_ERROR');
        }

        $path = (string) $file->getRealPath();
        $size = (int) @filesize($path);
        $maxKb = $this->policy->maxReceiptKb();

        if ($size <= 0) {
            $this->reject('The receipt is empty.', 'VALIDATION_ERROR');
        }
        if ($size > $maxKb * 1024) {
            $this->reject("The receipt may not be larger than {$maxKb} KB.", 'VALIDATION_ERROR');
        }

        $raw = (string) $file->getClientOriginalName();
        if (str_contains($raw, "\0") || $this->names->hasDangerousSegment($raw)) {
            $this->reject('This file name is not allowed.');
        }

        $display = $this->names->displayName($raw, 150);
        $extension = $this->names->extension($display);
        $allowed = (array) config('reimbursements.allowed', []);
        if ($extension === '' || ! array_key_exists($extension, $allowed)) {
            $this->reject('Only PDF, JPG and PNG receipts are allowed.');
        }

        $head = @file_get_contents($path, false, null, 0, 8192);
        if ($head === false) {
            $this->reject('The receipt could not be read.', 'VALIDATION_ERROR');
        }
        $detected = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($head);
        if (! in_array($detected, $allowed[$extension], true)) {
            $this->reject('The receipt content does not match its .'.$extension.' extension.');
        }

        match ($extension) {
            'pdf' => str_contains((string) file_get_contents($path, false, null, 0, 1024), '%PDF-') || $this->reject('The file is not a valid PDF.'),
            'jpg', 'jpeg' => $this->assertImage($path, IMAGETYPE_JPEG),
            'png' => $this->assertImage($path, IMAGETYPE_PNG),
            default => null,
        };
        $this->assertNoPhpTags($path);

        return new InspectedFile(
            file: $file,
            displayName: $display,
            extension: $extension,
            mimeType: (string) config("reimbursements.canonical_mime.{$extension}", $detected),
            sizeBytes: $size,
            sha256: (string) hash_file('sha256', $path),
        );
    }

    private function assertImage(string $path, int $expectedType): void
    {
        $info = @getimagesize($path);
        if ($info === false || $info[2] !== $expectedType) {
            $this->reject('The file is not a valid image.');
        }
    }

    /** A PHP payload smuggled inside an otherwise valid file (polyglot) is refused outright. */
    private function assertNoPhpTags(string $path): void
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            $this->reject('The receipt could not be read.', 'VALIDATION_ERROR');
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

    private function reject(string $message, string $code = 'UNSUPPORTED_FILE_TYPE'): never
    {
        throw ApiException::make($code, $message, 422, ['receipt' => [$message]]);
    }
}
