<?php

namespace App\Services\Documents;

use Illuminate\Http\UploadedFile;

/** An upload that passed every content check. Values here are server-derived, not client claims. */
final class InspectedFile
{
    public function __construct(
        public readonly UploadedFile $file,
        public readonly string $displayName,
        public readonly string $extension,
        public readonly string $mimeType,
        public readonly int $sizeBytes,
        public readonly string $sha256,
    ) {}
}
