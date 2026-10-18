<?php

use Illuminate\Support\Facades\Schedule;

// Re-render payslip PDFs that are missing (e.g. a crash right after finalization). Idempotent.
Schedule::command('payroll:payslips')->hourly()->withoutOverlapping();
