<?php

namespace App\Http\Resources;

use App\Enums\AttendanceEventType;
use App\Models\Attendance;
use App\Models\AttendanceEvent;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Attendance */
class AttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $schedule = $this->relationLoaded('schedule') ? $this->schedule : null;
        $events = $this->relationLoaded('events') ? $this->events : collect();

        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'work_date' => Fmt::date($this->work_date),
            'status' => $this->status->value,
            'needs_review' => $this->needs_review,
            'check_in_at' => Fmt::iso($this->check_in_at),
            'check_out_at' => Fmt::iso($this->check_out_at),
            'check_in_time' => Fmt::time($this->check_in_at),
            'check_out_time' => Fmt::time($this->check_out_at),
            'worked_minutes' => $this->worked_minutes,
            'late_minutes' => $this->late_minutes,
            'early_leave_minutes' => $this->early_leave_minutes,
            'is_late' => $this->is_late,
            'is_early_leave' => $this->is_early_leave,
            'review_note' => $this->review_note,
            'shift_name' => $schedule?->shift_name,
            'location_name' => $schedule?->location_name,
            'day_type' => $schedule?->day_type?->value,
            'expected_start_time' => Fmt::time($schedule?->expected_start_at),
            'expected_end_time' => Fmt::time($schedule?->expected_end_at),
            'check_in' => self::eventSummary($events->firstWhere('type', AttendanceEventType::CheckIn)),
            'check_out' => self::eventSummary($events->firstWhere('type', AttendanceEventType::CheckOut)),
        ];
    }

    /** @return array<string, mixed>|null */
    public static function eventSummary(?AttendanceEvent $event): ?array
    {
        if (! $event) {
            return null;
        }

        return [
            'event_id' => $event->id,
            'occurred_at' => Fmt::iso($event->occurred_at),
            'latitude' => $event->latitude,
            'longitude' => $event->longitude,
            'accuracy_meters' => $event->accuracy_meters,
            'distance_meters' => $event->distance_meters,
            'inside_geofence' => $event->inside_geofence,
            'is_offline_sync' => $event->is_offline_sync,
            'time_source' => $event->time_source,
            'has_selfie' => $event->selfie_path !== null,
            'selfie_url' => $event->selfie_path !== null ? '/api/v1/attendance-events/'.$event->id.'/selfie' : null,
        ];
    }
}
