<?php

namespace App\Services\Admin;

use App\Exceptions\ApiException;
use App\Models\AttendanceSchedule;
use App\Models\Holiday;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Admin\Concerns\AuditsChanges;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * Company holidays. Once a holiday has been frozen into a schedule snapshot its date is history:
 * it can be renamed but not moved or deleted (that would detach or contradict past schedules).
 */
class HolidayService
{
    use AuditsChanges;

    public const TYPES = ['national' => 'National', 'company' => 'Company', 'regional' => 'Regional'];

    public function __construct(
        private readonly CompanyContext $context,
        private readonly ActivityLogger $activity,
    ) {}

    /** @param array{date: string, name: string, type: string} $data */
    public function save(?Holiday $holiday, array $data, User $actor): Holiday
    {
        return DB::transaction(function () use ($holiday, $data, $actor) {
            $holiday ??= new Holiday(['company_id' => $this->context->id()]);

            if ($holiday->exists && $holiday->date->toDateString() !== $data['date'] && $this->isFrozen($holiday)) {
                throw ApiException::make('HOLIDAY_IN_USE', 'This holiday is already part of recorded schedules, so its date cannot change. Add a new holiday instead.', 409);
            }

            return $this->persist($holiday, $data, ['date', 'name', 'type'], 'holiday', $actor);
        });
    }

    public function delete(Holiday $holiday, User $actor): void
    {
        if ($this->isFrozen($holiday)) {
            throw ApiException::make('HOLIDAY_IN_USE', 'This holiday is already part of recorded schedules and is kept as history.', 409);
        }

        $this->remove($holiday, 'holiday', $actor, ['date' => $holiday->date->toDateString(), 'name' => $holiday->name]);
    }

    /** @param list<int> $ids @return list<int> the holidays already referenced by schedule snapshots */
    public function frozenIds(array $ids): array
    {
        return $ids === [] ? [] : AttendanceSchedule::query()->whereIn('holiday_id', $ids)->distinct()->pluck('holiday_id')->map(fn ($id) => (int) $id)->all();
    }

    public function isFrozen(Holiday $holiday): bool
    {
        return AttendanceSchedule::query()->where('holiday_id', $holiday->id)->exists();
    }
}
