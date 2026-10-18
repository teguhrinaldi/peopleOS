<?php

namespace App\Console\Commands;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Services\Attendance\ScheduleService;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Freezes each active employee's schedule for a day, at the start of that day.
 *
 * Without this, a snapshot only appears on first check-in, so a day nobody checked in on would have
 * no expected schedule at all and "absent" could not be reported. Idempotent: existing snapshots
 * are never rewritten.
 */
class BuildAttendanceSchedules extends Command
{
    protected $signature = 'attendance:build-schedules {--date= : Work date (Y-m-d), default today in the company timezone} {--days=1 : Number of consecutive days}';

    protected $description = 'Create the immutable attendance schedule snapshots for active employees';

    public function handle(ScheduleService $schedules, CompanyContext $context): int
    {
        $start = $this->option('date')
            ? CarbonImmutable::parse($this->option('date'), $context->timezone())
            : $context->today();
        $days = max(1, (int) $this->option('days'));
        $created = 0;

        Employee::query()->where('status', EmployeeStatus::Active->value)->orderBy('id')
            ->chunkById(200, function ($employees) use ($schedules, $start, $days, &$created) {
                foreach ($employees as $employee) {
                    for ($i = 0; $i < $days; $i++) {
                        $schedule = $schedules->forDate($employee, $start->addDays($i)->toDateString());
                        $created += $schedule->wasRecentlyCreated ? 1 : 0;
                    }
                }
            });

        $this->info("Schedules created: {$created}");

        return self::SUCCESS;
    }
}
