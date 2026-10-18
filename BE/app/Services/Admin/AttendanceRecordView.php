<?php

namespace App\Services\Admin;

use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceEvent;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/** Read model for one attendance record: frozen schedule, events with geometry, corrections, review state. */
class AttendanceRecordView
{
    /** @return array<string, mixed> */
    public function build(User $viewer, Attendance $attendance): array
    {
        $attendance->load(['employee', 'schedule', 'events']);
        $schedule = $attendance->schedule;

        $corrections = AttendanceCorrection::query()
            ->where('attendance_schedule_id', $attendance->attendance_schedule_id)
            ->with('reviewer:id,name')
            ->latest('id')->get();

        $isOwn = $viewer->employee?->id === $attendance->employee_id;

        return [
            'attendance' => $attendance,
            'reviewerName' => $attendance->reviewed_by ? User::withTrashed()->whereKey($attendance->reviewed_by)->value('name') : null,
            'schedule' => $schedule,
            'events' => $attendance->events,
            'corrections' => $corrections,
            'points' => $this->points($attendance->events, $schedule?->location_latitude, $schedule?->location_longitude),
            'canReview' => ! $isOwn && $attendance->status->value === 'PENDING' && Gate::forUser($viewer)->allows('review', $attendance),
            'canSeeSelfie' => Gate::forUser($viewer)->allows('viewSelfie', $attendance),
        ];
    }

    /**
     * Event positions in metres east/north of the location centre (equirectangular; exact enough for geofences).
     *
     * @param  Collection<int, AttendanceEvent>  $events
     * @return list<array{x: float, y: float, inside: ?bool, label: string}>
     */
    public function points(Collection $events, ?float $lat0, ?float $lng0): array
    {
        if ($lat0 === null || $lng0 === null) {
            return [];
        }

        $points = [];
        foreach ($events as $event) {
            if ($event->latitude === null || $event->longitude === null) {
                continue;
            }
            $points[] = [
                'x' => ($event->longitude - $lng0) * 111320 * cos(deg2rad($lat0)),
                'y' => ($event->latitude - $lat0) * 111320,
                'inside' => $event->inside_geofence,
                'label' => $event->type->value === 'CHECK_IN' ? 'In' : 'Out',
            ];
        }

        return $points;
    }
}
