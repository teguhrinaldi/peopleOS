<?php

use Illuminate\Support\Facades\Schedule;

/*
| Scheduled jobs. Production needs one cron entry:  * * * * * php /path/to/BE/artisan schedule:run
|
| Jobs are idempotent and run hourly rather than "at midnight", so they need no knowledge of the
| company timezone at boot (which would require a database connection before migrations exist).
*/
Schedule::command('attendance:build-schedules')->hourly()->withoutOverlapping();

// Feature modules register their own jobs in routes/schedule/<module>.php.
foreach (glob(__DIR__.'/schedule/*.php') as $moduleSchedule) {
    require $moduleSchedule;
}
