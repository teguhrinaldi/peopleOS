<?php

namespace App\Services\Admin;

use App\Exceptions\ApiException;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkingCalendar;
use App\Models\WorkingCalendarAssignment;
use App\Services\ActivityLogger;
use App\Services\Admin\Concerns\AuditsChanges;
use App\Services\Admin\Concerns\ManagesDateRanges;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Working calendars (which weekdays are worked) and their date-ranged per-employee assignments.
 * Exactly one calendar is the company default; ScheduleService falls back to it.
 */
class CalendarService
{
    use AuditsChanges, ManagesDateRanges;

    public const DAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    public function __construct(
        private readonly CompanyContext $context,
        private readonly ActivityLogger $activity,
    ) {}

    /** @param array{name: string, description: ?string, is_default: bool, days: list<int>} $data days = ISO weekdays that are worked */
    public function save(?WorkingCalendar $calendar, array $data, User $actor): WorkingCalendar
    {
        return DB::transaction(function () use ($calendar, $data, $actor) {
            $calendar ??= new WorkingCalendar(['company_id' => $this->context->id()]);

            if ($calendar->exists && $calendar->is_default && ! $data['is_default']) {
                throw ValidationException::withMessages(['is_default' => 'Make another calendar the default first; one calendar must always be the default.']);
            }

            $worked = array_values(array_unique(array_map('intval', $data['days'] ?? [])));
            sort($worked);
            $oldDays = $calendar->exists ? $this->label($calendar->days()->where('is_working_day', true)->pluck('day_of_week')->all()) : null;
            $newDays = $this->label($worked);

            if ($data['is_default']) {
                $this->clearOtherDefaults($calendar, $actor);
            }

            $this->persist($calendar, $data, ['name', 'description', 'is_default'], 'working_calendar', $actor,
                extraNew: $oldDays !== $newDays ? ['working_days' => $newDays] : [],
                extraOld: $oldDays !== null && $oldDays !== $newDays ? ['working_days' => $oldDays] : [],
            );

            foreach (array_keys(self::DAYS) as $day) {
                $calendar->days()->updateOrCreate(['day_of_week' => $day], ['is_working_day' => in_array($day, $worked, true)]);
            }

            return $calendar->load('days');
        });
    }

    public function delete(WorkingCalendar $calendar, User $actor): void
    {
        if ($calendar->is_default) {
            throw ApiException::make('CALENDAR_IS_DEFAULT', 'The default calendar cannot be deleted. Make another calendar the default first.', 409);
        }

        $inUse = WorkingCalendarAssignment::query()->where('working_calendar_id', $calendar->id)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $this->context->today()->toDateString()))
            ->exists();
        if ($inUse) {
            throw ApiException::make('CALENDAR_IN_USE', "{$calendar->name} is still assigned to employees. End those assignments first.", 409);
        }

        $this->remove($calendar, 'working_calendar', $actor, ['name' => $calendar->name]);
    }

    /** @param array{employee_id: int, working_calendar_id: int, effective_from: string, effective_to: ?string} $data */
    public function assign(array $data, User $actor): WorkingCalendarAssignment
    {
        return DB::transaction(function () use ($data, $actor) {
            Employee::query()->whereKey($data['employee_id'])->lockForUpdate()->first();

            $this->assertNoOverlap(
                WorkingCalendarAssignment::query()->where('employee_id', $data['employee_id']),
                $data['effective_from'], $data['effective_to'] ?? null, 'effective_from', 'effective_to', 'effective_from',
            );

            $assignment = WorkingCalendarAssignment::query()->create([
                'employee_id' => $data['employee_id'],
                'working_calendar_id' => $data['working_calendar_id'],
                'effective_from' => $data['effective_from'],
                'effective_to' => $data['effective_to'] ?? null,
            ]);

            $this->activity->log('calendar_assignment.created', $assignment, null, $assignment->only(['employee_id', 'working_calendar_id', 'effective_from', 'effective_to']), actor: $actor);

            return $assignment;
        });
    }

    /** @return 'deleted'|'ended' */
    public function endAssignment(WorkingCalendarAssignment $assignment, User $actor): string
    {
        return DB::transaction(fn () => $this->endOrDelete($assignment, 'effective_from', 'effective_to', 'calendar_assignment', $actor));
    }

    /** @param list<int> $days */
    public function label(array $days): string
    {
        sort($days);
        $names = array_map(fn ($d) => self::DAYS[(int) $d] ?? '?', $days);

        return $names === [] ? 'No working days' : implode(', ', $names);
    }

    private function clearOtherDefaults(WorkingCalendar $calendar, User $actor): void
    {
        $others = WorkingCalendar::query()->where('company_id', $this->context->id())->where('is_default', true)
            ->when($calendar->exists, fn ($q) => $q->whereKeyNot($calendar->id))
            ->lockForUpdate()->get();

        foreach ($others as $other) {
            $other->forceFill(['is_default' => false])->save();
            $this->activity->log('working_calendar.updated', $other, ['is_default' => true], ['is_default' => false], actor: $actor);
        }
    }
}
