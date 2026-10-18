<?php

namespace App\Services\Admin;

use App\Enums\EmployeeStatus;
use App\Exceptions\ApiException;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Admin\Concerns\AuditsChanges;
use App\Services\Admin\Concerns\ManagesDateRanges;
use App\Services\Attendance\ShiftCalculator;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shift definitions and date-ranged shift overrides.
 *
 * Every derived number (crosses midnight, unpaid break total, working minutes, break durations) is
 * computed here with ShiftCalculator; the form only supplies wall-clock times. Changing a shift only
 * affects schedules frozen from now on: attendance_schedules already written keep their snapshot.
 */
class ShiftSetupService
{
    use AuditsChanges, ManagesDateRanges;

    private const FIELDS = [
        'code', 'name', 'start_time', 'end_time', 'crosses_midnight', 'grace_late_minutes',
        'grace_early_leave_minutes', 'break_minutes', 'working_minutes', 'is_active',
    ];

    public function __construct(
        private readonly CompanyContext $context,
        private readonly ActivityLogger $activity,
        private readonly ShiftCalculator $calculator,
    ) {}

    /**
     * @param  array<string, mixed>  $data  code, name, start_time, end_time (H:i), grace_*, is_active,
     *                                      breaks: list<array{name: string, start_time: string, end_time: string, is_paid: bool}>
     */
    public function save(?Shift $shift, array $data, User $actor): Shift
    {
        $start = $this->hms($data['start_time']);
        $end = $this->hms($data['end_time']);
        $breaks = $this->breaks($start, $end, $data['breaks'] ?? []);

        $working = $this->calculator->workingMinutes($start, $end, $breaks);
        if ($working <= 0) {
            throw ValidationException::withMessages(['breaks' => 'Unpaid breaks leave no working time in this shift.']);
        }

        $data = array_merge($data, [
            'start_time' => $start,
            'end_time' => $end,
            'crosses_midnight' => $this->calculator->crossesMidnight($start, $end),
            'break_minutes' => array_sum(array_map(fn ($b) => $b['is_paid'] ? 0 : $b['duration_minutes'], $breaks)),
            'working_minutes' => $working,
        ]);

        return DB::transaction(function () use ($shift, $data, $breaks, $actor) {
            $shift ??= new Shift(['company_id' => $this->context->id()]);
            $oldBreaks = $shift->exists ? $this->describe($shift->breaks()->orderBy('start_time')->get()->toArray()) : null;
            $newBreaks = $this->describe($breaks);
            $breaksChanged = $oldBreaks !== $newBreaks;

            $this->persist(
                $shift, $data, self::FIELDS, 'shift', $actor,
                extraNew: $breaksChanged ? ['breaks' => $newBreaks] : [],
                extraOld: $breaksChanged && $oldBreaks !== null ? ['breaks' => $oldBreaks] : [],
            );

            if ($breaksChanged) {
                $shift->breaks()->delete();
                foreach ($breaks as $break) {
                    $shift->breaks()->create($break);
                }
            }

            return $shift->load('breaks');
        });
    }

    public function delete(Shift $shift, User $actor): void
    {
        $today = $this->context->today()->toDateString();

        $inUse = EmployeeAssignment::query()->where('shift_id', $shift->id)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today))
            ->whereHas('employee', fn ($q) => $q->where('status', '!=', EmployeeStatus::Terminated->value))
            ->exists()
            || ShiftAssignment::query()->where('shift_id', $shift->id)
                ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $today))
                ->exists();

        if ($inUse) {
            throw ApiException::make('SHIFT_IN_USE', "{$shift->name} is still assigned to employees. Reassign them first, or deactivate the shift instead.", 409);
        }

        $this->remove($shift, 'shift', $actor);
    }

    /** Current (non-terminated) employees per shift through their placement. @return array<int, int> */
    public function usage(): array
    {
        $today = $this->context->today()->toDateString();

        return EmployeeAssignment::query()
            ->whereNotNull('shift_id')
            ->where('effective_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today))
            ->whereHas('employee', fn ($q) => $q->where('status', '!=', EmployeeStatus::Terminated->value))
            ->groupBy('shift_id')
            ->selectRaw('shift_id, count(distinct employee_id) as n')
            ->pluck('n', 'shift_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /** @param array{employee_id: int, shift_id: int, start_date: string, end_date: ?string, note: ?string} $data */
    public function assign(array $data, User $actor): ShiftAssignment
    {
        return DB::transaction(function () use ($data, $actor) {
            Employee::query()->whereKey($data['employee_id'])->lockForUpdate()->first();

            $this->assertNoOverlap(
                ShiftAssignment::query()->where('employee_id', $data['employee_id']),
                $data['start_date'], $data['end_date'] ?? null, 'start_date', 'end_date',
            );

            $assignment = ShiftAssignment::query()->create([
                'employee_id' => $data['employee_id'],
                'shift_id' => $data['shift_id'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'] ?? null,
                'note' => $data['note'] ?? null,
                'created_by' => $actor->id,
            ]);

            $this->activity->log('shift_assignment.created', $assignment, null, $assignment->only(['employee_id', 'shift_id', 'start_date', 'end_date', 'note']), actor: $actor);

            return $assignment;
        });
    }

    /** @return 'deleted'|'ended' */
    public function endAssignment(ShiftAssignment $assignment, User $actor): string
    {
        return DB::transaction(fn () => $this->endOrDelete($assignment, 'start_date', 'end_date', 'shift_assignment', $actor));
    }

    /**
     * Validate and complete break rows: each must sit inside the shift and not overlap another.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{name: string, start_time: string, end_time: string, duration_minutes: int, is_paid: bool}>
     */
    private function breaks(string $shiftStart, string $shiftEnd, array $rows): array
    {
        $span = $this->calculator->spanMinutes($shiftStart, $shiftEnd);
        $origin = $this->minutes($shiftStart);
        $out = [];

        foreach (array_values($rows) as $i => $row) {
            $start = $this->hms($row['start_time']);
            $end = $this->hms($row['end_time']);
            $offset = ($this->minutes($start) - $origin + 1440) % 1440;
            $duration = $this->calculator->spanMinutes($start, $end);

            if ($duration >= $span || $offset + $duration > $span) {
                throw ValidationException::withMessages(["breaks.{$i}.start_time" => 'The break must fall inside the shift.']);
            }

            $out[] = ['name' => $row['name'], 'start_time' => $start, 'end_time' => $end, 'duration_minutes' => $duration,
                'is_paid' => (bool) ($row['is_paid'] ?? false), '_offset' => $offset];
        }

        usort($out, fn ($a, $b) => $a['_offset'] <=> $b['_offset']);
        for ($i = 1; $i < count($out); $i++) {
            if ($out[$i]['_offset'] < $out[$i - 1]['_offset'] + $out[$i - 1]['duration_minutes']) {
                throw ValidationException::withMessages(['breaks' => "Breaks \"{$out[$i - 1]['name']}\" and \"{$out[$i]['name']}\" overlap."]);
            }
        }

        return array_map(function ($b) {
            unset($b['_offset']);

            return $b;
        }, $out);
    }

    /** @param iterable<array<string, mixed>> $breaks @return list<string> e.g. "Lunch 12:00-13:00 unpaid" */
    private function describe(iterable $breaks): array
    {
        $lines = [];
        foreach ($breaks as $b) {
            $lines[] = sprintf('%s %s-%s %s', $b['name'], substr((string) $b['start_time'], 0, 5), substr((string) $b['end_time'], 0, 5), ! empty($b['is_paid']) ? 'paid' : 'unpaid');
        }
        sort($lines);

        return $lines;
    }

    private function hms(string $time): string
    {
        return strlen($time) === 5 ? "{$time}:00" : $time;
    }

    private function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $h * 60 + $m;
    }
}
