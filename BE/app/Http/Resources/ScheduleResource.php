<?php

namespace App\Http\Resources;

use App\Models\AttendanceSchedule;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AttendanceSchedule */
class ScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->exists ? $this->id : null,
            'work_date' => Fmt::date($this->work_date),
            'day_type' => $this->day_type->value,
            'is_working_day' => $this->is_working_day,
            'shift_name' => $this->shift_name,
            'start_time' => Fmt::time($this->expected_start_at),
            'end_time' => Fmt::time($this->expected_end_at),
            'expected_start_at' => Fmt::iso($this->expected_start_at),
            'expected_end_at' => Fmt::iso($this->expected_end_at),
            'crosses_midnight' => $this->expected_start_at && $this->expected_end_at
                && Fmt::date($this->expected_end_at->setTimezone($this->timezone)) !== Fmt::date($this->expected_start_at->setTimezone($this->timezone)),
            'grace_late_minutes' => $this->grace_late_minutes,
            'break_minutes' => $this->break_minutes,
            'expected_working_minutes' => $this->expected_working_minutes,
            'location' => $this->hasLocation() ? [
                'name' => $this->location_name,
                'latitude' => $this->location_latitude,
                'longitude' => $this->location_longitude,
                'radius_meters' => $this->location_radius_meters,
            ] : null,
            'policy' => [
                'require_gps' => $this->require_gps,
                'geofence_mode' => $this->geofence_mode->value,
                'require_selfie' => $this->require_selfie,
                'max_gps_accuracy_meters' => $this->max_gps_accuracy_meters,
                'offline_max_age_minutes' => $this->offline_max_age_minutes,
            ],
        ];
    }
}
