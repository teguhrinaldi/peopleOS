<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceEventType;
use App\Enums\AttendanceStatus;
use App\Enums\EmployeeStatus;
use App\Enums\GeofenceMode;
use App\Enums\RequestStatus;
use App\Exceptions\ApiException;
use App\Models\Attendance;
use App\Models\AttendanceEvent;
use App\Models\AttendanceSchedule;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Services\ActivityLogger;
use App\Services\Leave\LeaveLedger;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Check-in / check-out. Safe against double taps, retries, offline replays and simultaneous requests:
 *
 *  1. idempotency_key (UNIQUE)              -> the same request twice yields the same stored result
 *  2. attendance(employee, schedule) UNIQUE -> at most one attendance per employee per scheduled day
 *  3. transaction + FOR UPDATE on the day's schedule / attendance row -> writers are serialized
 *
 * Time: the server clock is authoritative. A device timestamp is only honoured for events flagged as
 * offline syncs, and only inside the policy's offline window (see effectiveTime()).
 */
class AttendanceService
{
    /** A shift can never legitimately last longer than this; forgotten check-outs go through a correction. */
    public const MAX_OPEN_MINUTES = 24 * 60;

    public function __construct(
        private readonly ScheduleService $schedules,
        private readonly ShiftCalculator $calculator,
        private readonly GeofenceService $geofence,
        private readonly CompanyContext $context,
        private readonly ActivityLogger $activity,
        private readonly LeaveLedger $leave,
    ) {}

    public function checkIn(Employee $employee, AttendanceCapture $capture, ?UploadedFile $selfie = null): AttendanceResult
    {
        return $this->process(AttendanceEventType::CheckIn, $employee, $capture, $selfie);
    }

    public function checkOut(Employee $employee, AttendanceCapture $capture, ?UploadedFile $selfie = null): AttendanceResult
    {
        return $this->process(AttendanceEventType::CheckOut, $employee, $capture, $selfie);
    }

    /** Recomputes lateness / early leave / worked minutes from the stored times and the frozen schedule. */
    public function recalculate(Attendance $attendance, AttendanceSchedule $schedule): void
    {
        $late = 0;
        $early = 0;
        $worked = 0;

        if ($attendance->check_in_at && $schedule->is_working_day && $schedule->expected_start_at) {
            $late = $this->calculator->lateMinutes($schedule->expected_start_at, $attendance->check_in_at, $schedule->grace_late_minutes);
        }

        if ($attendance->check_out_at) {
            if ($schedule->is_working_day && $schedule->expected_end_at) {
                $early = $this->calculator->earlyLeaveMinutes($schedule->expected_end_at, $attendance->check_out_at, $schedule->grace_early_leave_minutes);
            }
            if ($attendance->check_in_at) {
                $worked = $this->calculator->workedMinutes($attendance->check_in_at, $attendance->check_out_at, $schedule->break_minutes);
            }
        }

        $attendance->late_minutes = $late;
        $attendance->is_late = $late > 0;
        $attendance->early_leave_minutes = $early;
        $attendance->is_early_leave = $early > 0;
        $attendance->worked_minutes = $worked;
    }

    private function process(AttendanceEventType $type, Employee $employee, AttendanceCapture $capture, ?UploadedFile $selfie): AttendanceResult
    {
        if ($replay = $this->replayIfKnown($employee, $type, $capture->idempotencyKey)) {
            return $replay;
        }

        $this->assertCanAttend($employee);

        $received = CarbonImmutable::instance(now())->utc();
        $preliminary = $this->preliminaryTime($capture, $received);

        $schedule = $type === AttendanceEventType::CheckIn
            ? $this->scheduleForCheckIn($employee, $preliminary)
            : $this->scheduleForCheckOut($employee);

        [$effective, $source] = $this->effectiveTime($capture, $received, $schedule);
        $verdict = $this->verify($schedule, $capture, $selfie, $effective);

        $storedSelfie = $selfie ? $this->storeSelfie($employee, $selfie) : null;

        try {
            $result = DB::transaction(fn () => $type === AttendanceEventType::CheckIn
                ? $this->recordCheckIn($employee, $schedule, $capture, $received, $effective, $source, $verdict, $storedSelfie)
                : $this->recordCheckOut($employee, $capture, $received, $effective, $source, $verdict, $storedSelfie));
        } catch (UniqueConstraintViolationException $e) {
            $this->discardSelfie($storedSelfie);

            // Lost a race against the very same request (retry / double tap): return the winner's result.
            if ($replay = $this->replayIfKnown($employee, $type, $capture->idempotencyKey)) {
                return $replay;
            }

            throw ApiException::make(
                $type === AttendanceEventType::CheckIn ? 'ALREADY_CHECKED_IN' : 'ALREADY_CHECKED_OUT',
                'This attendance action was already recorded.',
                409,
            );
        } catch (ApiException $e) {
            $this->discardSelfie($storedSelfie);

            // A concurrent request with the SAME key may have just committed; that is a replay, not a conflict.
            if ($replay = $this->replayIfKnown($employee, $type, $capture->idempotencyKey)) {
                return $replay;
            }

            throw $e;
        } catch (\Throwable $e) {
            $this->discardSelfie($storedSelfie);
            throw $e;
        }

        return $result;
    }

    // -------------------------------------------------------------- recording

    /** @param array{distance: ?float, inside: ?bool, review: bool} $verdict */
    private function recordCheckIn(
        Employee $employee,
        AttendanceSchedule $schedule,
        AttendanceCapture $capture,
        CarbonImmutable $received,
        CarbonImmutable $effective,
        string $source,
        array $verdict,
        ?array $selfie,
    ): AttendanceResult {
        // Serialize concurrent writers for this employee-day.
        AttendanceSchedule::query()->whereKey($schedule->id)->lockForUpdate()->first();

        $existing = Attendance::query()
            ->where('employee_id', $employee->id)
            ->where('attendance_schedule_id', $schedule->id)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            throw ApiException::make(
                $existing->check_out_at ? 'ALREADY_CHECKED_OUT' : 'ALREADY_CHECKED_IN',
                $existing->check_out_at ? 'Attendance for this day is already complete.' : 'You are already checked in.',
                409,
            );
        }

        $attendance = new Attendance([
            'employee_id' => $employee->id,
            'attendance_schedule_id' => $schedule->id,
            'work_date' => $schedule->work_date->toDateString(),
            'status' => $verdict['review'] ? AttendanceStatus::Pending : AttendanceStatus::CheckedIn,
            'needs_review' => $verdict['review'],
            'check_in_at' => $effective,
        ]);
        $this->recalculate($attendance, $schedule);
        $attendance->save();

        $event = $this->createEvent($attendance, $employee, AttendanceEventType::CheckIn, $capture, $received, $effective, $source, $verdict, $selfie);

        $this->activity->log('attendance.check_in', $attendance, null, [
            'work_date' => $attendance->work_date->toDateString(),
            'check_in_at' => $effective->toIso8601String(),
            'inside_geofence' => $verdict['inside'],
            'needs_review' => $verdict['review'],
            'offline_sync' => $capture->isOfflineSync,
        ], actor: $employee->user_id);

        return new AttendanceResult($attendance->load('events'), $event);
    }

    /** @param array{distance: ?float, inside: ?bool, review: bool} $verdict */
    private function recordCheckOut(
        Employee $employee,
        AttendanceCapture $capture,
        CarbonImmutable $received,
        CarbonImmutable $effective,
        string $source,
        array $verdict,
        ?array $selfie,
    ): AttendanceResult {
        $open = $this->findOpenAttendance($employee);
        $attendance = $open ? Attendance::query()->whereKey($open->id)->lockForUpdate()->first() : null;

        if (! $attendance || $attendance->check_out_at !== null || ! $attendance->isOpen()) {
            throw ApiException::make('NOT_CHECKED_IN', 'You are not checked in.', 409);
        }

        if ($effective->lessThanOrEqualTo($attendance->check_in_at)) {
            throw ApiException::make('INVALID_EVENT_TIME', 'Check-out must be after check-in.');
        }

        if ($this->calculator->minutesBetween($attendance->check_in_at, $effective) > self::MAX_OPEN_MINUTES) {
            throw ApiException::make('CHECK_OUT_TOO_LATE', 'This check-in is too old to close. Submit an attendance correction.', 409);
        }

        $schedule = $attendance->schedule;
        $attendance->check_out_at = $effective;
        $attendance->needs_review = $attendance->needs_review || $verdict['review'];
        $attendance->status = $attendance->needs_review ? AttendanceStatus::Pending : AttendanceStatus::CheckedOut;
        $this->recalculate($attendance, $schedule);
        $attendance->save();

        $event = $this->createEvent($attendance, $employee, AttendanceEventType::CheckOut, $capture, $received, $effective, $source, $verdict, $selfie);

        $this->activity->log('attendance.check_out', $attendance, null, [
            'work_date' => $attendance->work_date->toDateString(),
            'check_out_at' => $effective->toIso8601String(),
            'worked_minutes' => $attendance->worked_minutes,
            'inside_geofence' => $verdict['inside'],
            'offline_sync' => $capture->isOfflineSync,
        ], actor: $employee->user_id);

        return new AttendanceResult($attendance->load('events'), $event);
    }

    /** @param array{distance: ?float, inside: ?bool, review: bool} $verdict */
    private function createEvent(
        Attendance $attendance,
        Employee $employee,
        AttendanceEventType $type,
        AttendanceCapture $capture,
        CarbonImmutable $received,
        CarbonImmutable $effective,
        string $source,
        array $verdict,
        ?array $selfie,
    ): AttendanceEvent {
        return AttendanceEvent::query()->create([
            'attendance_id' => $attendance->id,
            'employee_id' => $employee->id,
            'type' => $type,
            'idempotency_key' => $capture->idempotencyKey,
            'occurred_at' => $effective,
            'received_at' => $received,
            'device_captured_at' => $capture->deviceCapturedAt,
            'time_source' => $source,
            'is_offline_sync' => $capture->isOfflineSync,
            'latitude' => $capture->latitude,
            'longitude' => $capture->longitude,
            'accuracy_meters' => $capture->accuracyMeters,
            'location_captured_at' => $capture->locationCapturedAt,
            'is_mock_location' => $capture->isMockLocation,
            'distance_meters' => $verdict['distance'],
            'inside_geofence' => $verdict['inside'],
            'selfie_path' => $selfie['path'] ?? null,
            'selfie_meta' => $selfie['meta'] ?? null,
            'device_info' => $capture->deviceInfo,
        ]);
    }

    // ------------------------------------------------------------ validation

    private function assertCanAttend(Employee $employee): void
    {
        if ($employee->status === EmployeeStatus::Terminated) {
            throw ApiException::make('EMPLOYEE_TERMINATED', 'Terminated employees cannot record attendance.', 403);
        }

        if ($employee->status !== EmployeeStatus::Active) {
            throw ApiException::make('EMPLOYEE_NOT_ACTIVE', 'Only active employees can record attendance.', 403);
        }
    }

    private function scheduleForCheckIn(Employee $employee, CarbonImmutable $moment): AttendanceSchedule
    {
        $schedule = $this->schedules->forDate($employee, $this->schedules->workDateFor($employee, $moment));

        if ($schedule->shift_id === null) {
            throw ApiException::make('NO_SCHEDULE', 'You have no shift assigned for this day.');
        }

        if (! $schedule->is_working_day && ! $this->hasApprovedOvertime($employee, $schedule)) {
            throw ApiException::make('NOT_A_WORKING_DAY', 'Today is not a working day for you.');
        }

        // Half-day leave still allows attendance for the other half.
        if ($this->leave->coverageOn($employee, $schedule->work_date->toDateString()) === 'FULL') {
            throw ApiException::make('ON_LEAVE', 'You are on approved leave today. Cancel the leave first if you are working.', 409);
        }

        return $schedule;
    }

    private function scheduleForCheckOut(Employee $employee): AttendanceSchedule
    {
        $open = $this->findOpenAttendance($employee);

        if (! $open) {
            throw ApiException::make('NOT_CHECKED_IN', 'You are not checked in.', 409);
        }

        return $open->schedule;
    }

    private function findOpenAttendance(Employee $employee): ?Attendance
    {
        return Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereNotNull('check_in_at')
            ->whereNull('check_out_at')
            ->whereIn('status', [AttendanceStatus::CheckedIn->value, AttendanceStatus::Pending->value])
            ->orderByDesc('check_in_at')
            ->with('schedule')
            ->first();
    }

    private function hasApprovedOvertime(Employee $employee, AttendanceSchedule $schedule): bool
    {
        return OvertimeRequest::query()
            ->where('employee_id', $employee->id)
            ->where('status', RequestStatus::Approved->value)
            ->whereDate('work_date', $schedule->work_date->toDateString())
            ->exists();
    }

    /** Timestamp used only to pick the work date, before the schedule (and its policy) is known. */
    private function preliminaryTime(AttendanceCapture $capture, CarbonImmutable $received): CarbonImmutable
    {
        if ($capture->isOfflineSync && $capture->deviceCapturedAt && $capture->deviceCapturedAt->lessThanOrEqualTo($received)) {
            return $capture->deviceCapturedAt;
        }

        return $received;
    }

    /**
     * The instant that attendance maths uses.
     *  - normal request: the server's clock, always.
     *  - offline sync: the device timestamp, but only if it is not in the future (small skew tolerated,
     *    then clamped to "now") and not older than the policy's offline window.
     *
     * @return array{0: CarbonImmutable, 1: string}
     */
    private function effectiveTime(AttendanceCapture $capture, CarbonImmutable $received, AttendanceSchedule $schedule): array
    {
        if (! $capture->isOfflineSync) {
            return [$received, 'server'];
        }

        if (! $capture->deviceCapturedAt) {
            throw ApiException::make('INVALID_EVENT_TIME', 'Offline events must include the time they were captured.');
        }

        $skew = (int) config('peopleos.attendance.device_clock_skew_seconds', 300);
        if ($capture->deviceCapturedAt->getTimestamp() > $received->getTimestamp() + $skew) {
            throw ApiException::make('INVALID_EVENT_TIME', 'The device clock is ahead of the server.');
        }

        $ageMinutes = $this->calculator->minutesBetween($capture->deviceCapturedAt, $received);
        if ($ageMinutes > $schedule->offline_max_age_minutes) {
            throw ApiException::make('OFFLINE_EVENT_TOO_OLD', 'This offline record is too old to sync. Submit an attendance correction.', 422, [
                'max_age_minutes' => $schedule->offline_max_age_minutes,
            ]);
        }

        $effective = $capture->deviceCapturedAt->greaterThan($received) ? $received : $capture->deviceCapturedAt;

        return [$effective, 'device_offline'];
    }

    /**
     * GPS, mock-location, accuracy, geofence and selfie policy. Distance is always computed here.
     *
     * @return array{distance: ?float, inside: ?bool, review: bool}
     */
    private function verify(AttendanceSchedule $schedule, AttendanceCapture $capture, ?UploadedFile $selfie, CarbonImmutable $effective): array
    {
        $hasCoordinates = $capture->latitude !== null && $capture->longitude !== null;

        if ($schedule->require_gps && ! $hasCoordinates) {
            throw ApiException::make('GPS_REQUIRED', 'Location is required to record attendance.');
        }

        if ($schedule->reject_mock_location && $capture->isMockLocation) {
            throw ApiException::make('MOCK_LOCATION_DETECTED', 'Mock locations are not allowed.');
        }

        $distance = null;
        $inside = null;
        $review = false;

        if ($hasCoordinates) {
            $this->geofence->assertValidCoordinate($capture->latitude, $capture->longitude);

            if ($capture->accuracyMeters !== null && $capture->accuracyMeters > $schedule->max_gps_accuracy_meters) {
                throw ApiException::make('GPS_ACCURACY_TOO_LOW', 'GPS accuracy is too low. Move to an open area and try again.', 422, [
                    'accuracy_meters' => $capture->accuracyMeters,
                    'max_accuracy_meters' => $schedule->max_gps_accuracy_meters,
                ]);
            }

            if (! $capture->isOfflineSync && $capture->locationCapturedAt) {
                $staleSeconds = $effective->getTimestamp() - $capture->locationCapturedAt->getTimestamp();
                if ($staleSeconds > 300) {
                    throw ApiException::make('LOCATION_STALE', 'The location fix is too old. Refresh your location.');
                }
            }

            if ($schedule->geofence_mode !== GeofenceMode::Off && $schedule->hasLocation()) {
                $evaluation = $this->geofence->evaluate(
                    $schedule->location_latitude,
                    $schedule->location_longitude,
                    (int) $schedule->location_radius_meters,
                    $capture->latitude,
                    $capture->longitude,
                );
                $distance = $evaluation['distance'];
                $inside = $evaluation['inside'];

                if (! $inside) {
                    if ($schedule->geofence_mode === GeofenceMode::Enforce) {
                        throw ApiException::make('OUTSIDE_GEOFENCE', 'You are outside the allowed work area.', 422, [
                            'distance_meters' => $distance,
                            'radius_meters' => $schedule->location_radius_meters,
                            'location' => $schedule->location_name,
                        ]);
                    }
                    $review = true;
                }
            }
        }

        if ($schedule->require_selfie && ! $selfie) {
            throw ApiException::make('SELFIE_REQUIRED', 'A selfie is required to record attendance.');
        }

        return ['distance' => $distance, 'inside' => $inside, 'review' => $review];
    }

    // ---------------------------------------------------------------- helpers

    private function replayIfKnown(Employee $employee, AttendanceEventType $type, string $key): ?AttendanceResult
    {
        $event = AttendanceEvent::query()->where('idempotency_key', $key)->first();

        if (! $event) {
            return null;
        }

        if ($event->employee_id !== $employee->id || $event->type !== $type) {
            throw ApiException::make('IDEMPOTENCY_KEY_CONFLICT', 'This request key was already used for a different action.', 409);
        }

        return new AttendanceResult($event->attendance->load('events'), $event, replayed: true);
    }

    /** @return array{path: string, meta: array<string, mixed>} */
    private function storeSelfie(Employee $employee, UploadedFile $file): array
    {
        $disk = Storage::disk(config('peopleos.attendance.selfie_disk'));
        $directory = sprintf('attendance-selfies/%d/%s', $employee->id, $this->context->now()->format('Y/m'));
        $path = $disk->putFile($directory, $file);

        if ($path === false) {
            throw ApiException::make('SELFIE_STORAGE_FAILED', 'The selfie could not be stored. Please try again.', 500);
        }

        return [
            'path' => $path,
            'meta' => [
                'mime' => $file->getMimeType(),
                'size' => $file->getSize(),
                'sha256' => hash_file('sha256', $file->getRealPath()),
            ],
        ];
    }

    /** @param array{path: string}|null $stored */
    private function discardSelfie(?array $stored): void
    {
        if ($stored) {
            Storage::disk(config('peopleos.attendance.selfie_disk'))->delete($stored['path']);
        }
    }
}
