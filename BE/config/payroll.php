<?php

use App\Services\Overtime\OvertimeEarningSource;

return [
    /*
    | Private (non-public) disk for payslip PDFs. Files are only ever streamed through the authorised
    | /api/v1/payslips/{id}/pdf endpoint; never point this at a publicly served disk.
    */
    'payslip_disk' => env('PEOPLEOS_PAYSLIP_DISK', 'local'),
    'payslip_directory' => 'payslips',

    /*
    | A generation that has been PROCESSING for longer than this is considered crashed and may be taken
    | over by a new "generate" request (otherwise 409 PAYROLL_PROCESSING).
    */
    /*
    | Earning sources: modules that add traceable lines (with source record ids) to payroll items and guarantee
    | each record is paid exactly once. Each class implements App\Services\Payroll\Contracts\PayrollEarningSource
    | (see docs/payroll.md "Earning sources"). Adding a module = adding its class here; no payroll code changes.
    */
    'earning_sources' => [
        OvertimeEarningSource::class,
    ],

    'processing_timeout_minutes' => (int) env('PEOPLEOS_PAYROLL_PROCESSING_TIMEOUT', 15),

    /*
    | Currency minor units used when Settings `payroll.rounding_decimals` is "auto". Anything not listed
    | rounds to 2 decimals. Values above 2 are capped at 2 because money columns are DECIMAL(15,2).
    */
    'currency_decimals' => [
        'IDR' => 0, 'JPY' => 0, 'KRW' => 0, 'VND' => 0, 'CLP' => 0, 'ISK' => 0, 'UGX' => 0, 'PYG' => 0,
        'XAF' => 0, 'XOF' => 0, 'RWF' => 0, 'KMF' => 0, 'GNF' => 0, 'VUV' => 0,
    ],
];
