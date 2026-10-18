<?php

namespace App\Services\Admin;

use App\Enums\EmployeeStatus;
use App\Exceptions\ApiException;
use App\Models\AttendancePolicy;
use App\Models\EmployeeAssignment;
use App\Models\User;
use App\Models\WorkLocation;
use App\Services\ActivityLogger;
use App\Services\Admin\Concerns\AuditsChanges;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Work locations (geofences) and attendance policies. Schedules copy a location's coordinates,
 * radius and policy when they are frozen, so editing here never rewrites recorded attendance.
 */
class LocationService
{
    use AuditsChanges;

    private const LOCATION_FIELDS = ['branch_id', 'attendance_policy_id', 'code', 'name', 'address', 'latitude', 'longitude', 'radius_meters', 'is_active'];

    private const POLICY_FIELDS = ['name', 'is_default', 'require_gps', 'geofence_mode', 'require_selfie', 'max_gps_accuracy_meters', 'reject_mock_location', 'offline_max_age_minutes'];

    public function __construct(
        private readonly CompanyContext $context,
        private readonly ActivityLogger $activity,
    ) {}

    /** @param array<string, mixed> $data */
    public function saveLocation(?WorkLocation $location, array $data, User $actor): WorkLocation
    {
        return DB::transaction(function () use ($location, $data, $actor) {
            $location ??= new WorkLocation(['company_id' => $this->context->id()]);

            return $this->persist($location, $data, self::LOCATION_FIELDS, 'work_location', $actor);
        });
    }

    public function deleteLocation(WorkLocation $location, User $actor): void
    {
        $inUse = EmployeeAssignment::query()->where('work_location_id', $location->id)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $this->context->today()->toDateString()))
            ->whereHas('employee', fn ($q) => $q->where('status', '!=', EmployeeStatus::Terminated->value))
            ->exists();

        if ($inUse) {
            throw ApiException::make('LOCATION_IN_USE', "{$location->name} is still assigned to employees. Reassign them first, or deactivate the location instead.", 409);
        }

        $this->remove($location, 'work_location', $actor);
    }

    /** Current (non-terminated) employees per location. @return array<int, int> */
    public function usage(): array
    {
        $today = $this->context->today()->toDateString();

        return EmployeeAssignment::query()
            ->whereNotNull('work_location_id')
            ->where('effective_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today))
            ->whereHas('employee', fn ($q) => $q->where('status', '!=', EmployeeStatus::Terminated->value))
            ->groupBy('work_location_id')
            ->selectRaw('work_location_id, count(distinct employee_id) as n')
            ->pluck('n', 'work_location_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /** @param array<string, mixed> $data */
    public function savePolicy(?AttendancePolicy $policy, array $data, User $actor): AttendancePolicy
    {
        return DB::transaction(function () use ($policy, $data, $actor) {
            $policy ??= new AttendancePolicy(['company_id' => $this->context->id()]);

            if ($policy->exists && $policy->is_default && ! $data['is_default']) {
                throw ValidationException::withMessages(['is_default' => 'Make another policy the default first; locations without a policy use the default.']);
            }

            if ($data['is_default']) {
                $others = AttendancePolicy::query()->where('company_id', $this->context->id())->where('is_default', true)
                    ->when($policy->exists, fn ($q) => $q->whereKeyNot($policy->id))->lockForUpdate()->get();
                foreach ($others as $other) {
                    $other->forceFill(['is_default' => false])->save();
                    $this->activity->log('attendance_policy.updated', $other, ['is_default' => true], ['is_default' => false], actor: $actor);
                }
            }

            return $this->persist($policy, $data, self::POLICY_FIELDS, 'attendance_policy', $actor);
        });
    }

    public function deletePolicy(AttendancePolicy $policy, User $actor): void
    {
        if ($policy->is_default) {
            throw ApiException::make('POLICY_IS_DEFAULT', 'The default policy cannot be deleted. Make another policy the default first.', 409);
        }
        if (WorkLocation::query()->where('attendance_policy_id', $policy->id)->exists()) {
            throw ApiException::make('POLICY_IN_USE', "{$policy->name} is used by work locations. Move them to another policy first.", 409);
        }

        $this->remove($policy, 'attendance_policy', $actor, ['name' => $policy->name]);
    }
}
