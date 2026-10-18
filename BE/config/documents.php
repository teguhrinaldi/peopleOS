<?php

/*
| Document management (docs/documents.md).
| Files live on a PRIVATE disk and are only ever streamed through the authorized API/admin endpoints.
| Never point `disk` at the `public` disk or at anything the web server serves directly.
*/
return [
    // Filesystem disk (config/filesystems.php). `local` = storage/app/private.
    'disk' => env('PEOPLEOS_DOCUMENT_DISK', 'local'),

    // Directory inside the disk: {directory}/{employeeId|company}/{random}
    'directory' => 'documents',

    // Maximum upload size in kilobytes (PHP's upload_max_filesize / post_max_size must be at least this).
    'max_kb' => (int) env('PEOPLEOS_DOCUMENT_MAX_KB', 10240),

    /*
    | Allowed extensions => MIME types accepted for that extension, detected from the file CONTENT (finfo).
    | Office files are ZIP containers: some libmagic builds report them as application/zip, so their
    | internal structure is verified as well (see DocumentFileInspector).
    */
    'allowed' => [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
    ],

    // The canonical MIME stored and served for each extension (never the client-declared one).
    'canonical_mime' => [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ],
];
