<?php

/*
| Reimbursements (docs/reimbursements.md).
| Receipts live on a PRIVATE disk and are only ever streamed through the authorized API.
| Business rules (categories, limits, payment route, receipt size) are stored in the database; this file only
| holds infrastructure settings and hard ceilings.
*/
return [
    'disk' => env('PEOPLEOS_RECEIPT_DISK', 'local'),

    // Directory inside the disk: {directory}/{employeeId}/{random}
    'directory' => 'receipts',

    // Hard ceiling in KB. The company policy (expense_policies.max_receipt_kb) may only lower it.
    'max_kb_ceiling' => (int) env('PEOPLEOS_RECEIPT_MAX_KB', 10240),

    'allowed' => [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
    ],

    'canonical_mime' => [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
    ],
];
