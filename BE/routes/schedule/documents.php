<?php

use Illuminate\Support\Facades\Schedule;

// Required from routes/console.php. Hourly (no timezone knowledge needed at boot) and idempotent:
// statuses are recomputed from the company-timezone date and notifications are deduplicated.
Schedule::command('documents:refresh-status')->hourly()->withoutOverlapping(30);
