<?php

namespace Tests\Feature\Api;

use App\Enums\EmployeeStatus;
use App\Enums\GeofenceMode;
use App\Models\Activity;
use App\Models\Attendance;
use App\Models\AttendanceEvent;
use App\Models\AttendanceSchedule;
use App\Models\EmployeeAssignment;
use App\Models\OvertimeRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\WorkplaceFixtures;
use Tests\TestCase;

class AttendanceCheckInTest extends TestCase
{
    use RefreshDatabase, WorkplaceFixtures;

    private const IN = '/api/v1/attendance/check-in';

    private const OUT = '/api/v1/attendance/check-out';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkplace();
        Storage::fake('local');
    }

    public function test_employee_checks_in_and_out_inside_the_geofence(): void
    {
        [$user] = $this->makeStaff();
        $this->actingAsApi($user);

        $this->at('2026-09-30 08:05'); // Wednesday
        $this->postJson(self::IN, $this->capture('in-key-0001'))
            ->assertCreated()
            ->assertJsonPath('data.attendance.status', 'CHECKED_IN')
            ->assertJsonPath('data.attendance.check_in_time', '08:05')
            ->assertJsonPath('data.attendance.is_late', false)
            ->assertJsonPath('data.event.inside_geofence', true)
            ->assertJsonPath('data.replayed', false);

        $this->at('2026-09-30 17:10');
        $this->postJson(self::OUT, $this->capture('out-key-0001'))
            ->assertCreated()
            ->assertJsonPath('data.attendance.status', 'CHECKED_OUT')
            ->assertJsonPath('data.attendance.worked_minutes', 485)
            ->assertJsonPath('data.attendance.is_early_leave', false);

        $this->assertSame(1, Attendance::query()->count());
        $this->assertSame(2, AttendanceEvent::query()->count());
    }

    public function test_lateness_is_measured_against_the_frozen_schedule_and_grace(): void
    {
        [$user] = $this->makeStaff();
        $this->actingAsApi($user);

        $this->at('2026-09-30 08:10'); // exactly at the 10 minute grace edge
        $this->postJson(self::IN, $this->capture('late-a-0001'))->assertJsonPath('data.attendance.is_late', false);
    }

    public function test_arriving_after_grace_counts_the_full_delay_as_late(): void
    {
        [$user] = $this->makeStaff();
        $this->actingAsApi($user);

        $this->at('2026-09-30 08:25');
        $this->postJson(self::IN, $this->capture('late-b-0001'))
            ->assertJsonPath('data.attendance.is_late', true)
            ->assertJsonPath('data.attendance.late_minutes', 25);
    }

    public function test_terminated_employee_cannot_check_in(): void
    {
        [$user, $employee] = $this->makeStaff();
        $employee->update(['status' => EmployeeStatus::Terminated->value]);
        $this->actingAsApi($user);
        $this->at('2026-09-30 08:00');

        $this->postJson(self::IN, $this->capture('term-key-0001'))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'EMPLOYEE_TERMINATED');

        $this->assertSame(0, Attendance::query()->count());
    }

    public function test_pending_and_inactive_employees_cannot_check_in(): void
    {
        foreach ([EmployeeStatus::Pending, EmployeeStatus::Inactive] as $i => $status) {
            [$user] = $this->makeStaff('employee', ['status' => $status->value]);
            $this->actingAsApi($user);
            $this->at('2026-09-30 08:00');

            $this->postJson(self::IN, $this->capture("state-key-000{$i}"))
                ->assertForbidden()
                ->assertJsonPath('error.code', 'EMPLOYEE_NOT_ACTIVE');
        }
    }

    public function test_server_rejects_a_location_outside_the_geofence_regardless_of_the_client(): void
    {
        [$user] = $this->makeStaff();
        $this->actingAsApi($user);
        $this->at('2026-09-30 08:00');

        $this->postJson(self::IN, $this->capture('far-key-0001', -6.9500, 107.6071, ['inside_geofence' => true]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'OUTSIDE_GEOFENCE')
            ->assertJsonPath('error.details.radius_meters', 150);

        $this->assertSame(0, Attendance::query()->count());
    }

    public function test_review_mode_accepts_outside_checkin_but_flags_it_pending(): void
    {
        $this->policy->update(['geofence_mode' => GeofenceMode::Review]);
        [$user] = $this->makeStaff();
        $this->actingAsApi($user);
        $this->at('2026-09-30 08:00');

        $this->postJson(self::IN, $this->capture('rev-key-0001', -6.9500, 107.6071))
            ->assertCreated()
            ->assertJsonPath('data.attendance.status', 'PENDING')
            ->assertJsonPath('data.attendance.needs_review', true)
            ->assertJsonPath('data.event.inside_geofence', false);
    }

    public function test_gps_policy_violations_are_rejected(): void
    {
        [$user] = $this->makeStaff();
        $this->actingAsApi($user);
        $this->at('2026-09-30 08:00');

        $this->postJson(self::IN, ['idempotency_key' => 'nogps-key-0001'])
            ->assertStatus(422)->assertJsonPath('error.code', 'GPS_REQUIRED');

        $this->postJson(self::IN, $this->capture('acc-key-00001', extra: ['accuracy_meters' => 500]))
            ->assertStatus(422)->assertJsonPath('error.code', 'GPS_ACCURACY_TOO_LOW');

        $this->postJson(self::IN, $this->capture('mock-key-0001', extra: ['is_mock_location' => true]))
            ->assertStatus(422)->assertJsonPath('error.code', 'MOCK_LOCATION_DETECTED');

        $this->postJson(self::IN, $this->capture('bad-key-00001', 123.0, 107.0))
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_stale_location_fix_is_rejected_for_online_requests(): void
    {
        [$user] = $this->makeStaff();
        $this->actingAsApi($user);
        $this->at('2026-09-30 08:00');

        $this->postJson(self::IN, $this->capture('stale-key-001', extra: ['location_captured_at' => '2026-09-30T07:30:00+07:00']))
            ->assertStatus(422)->assertJsonPath('error.code', 'LOCATION_STALE');
    }

    public function test_double_check_in_is_a_conflict_and_does_not_create_a_second_row(): void
    {
        [$user] = $this->makeStaff();
        $this->actingAsApi($user);
        $this->at('2026-09-30 08:00');

        $this->postJson(self::IN, $this->capture('dbl-key-00001'))->assertCreated();
        $this->postJson(self::IN, $this->capture('dbl-key-00002'))
            ->assertStatus(409)->assertJsonPath('error.code', 'ALREADY_CHECKED_IN');

        $this->assertSame(1, Attendance::query()->count());
        $this->assertSame(1, AttendanceEvent::query()->count());
    }

    public function test_double_check_out_is_a_conflict(): void
    {
        [$user] = $this->makeStaff();
        $this->actingAsApi($user);
        $this->at('2026-09-30 08:00');
        $this->postJson(self::IN, $this->capture('co-in-key-001'))->assertCreated();
        $this->at('2026-09-30 17:00');
        $this->postJson(self::OUT, $this->capture('co-out-key-01'))->assertCreated();

        $this->postJson(self::OUT, $this->capture('co-out-key-02'))
            ->assertStatus(409)->assertJsonPath('error.code', 'NOT_CHECKED_IN');
    }

    public function test_check_out_without_check_in_is_a_conflict(): void
    {
        [$user] = $this->makeStaff();
        $this->actingAsApi($user);
        $this->at('2026-09-30 17:00');

        $this->postJson(self::OUT, $this->capture('lonely-out-01'))
            ->assertStatus(409)->assertJsonPath('error.code', 'NOT_CHECKED_IN');
    }

    public function test_retrying_the_same_idempotency_key_returns_the_stored_result_without_a_duplicate(): void
    {
        [$user] = $this->makeStaff();
        $this->actingAsApi($user);
        $this->at('2026-09-30 08:00');

        $first = $this->postJson(self::IN, $this->capture('idem-key-0001'))->assertCreated();
        $this->at('2026-09-30 08:03'); // the retry arrives later
        $retry = $this->postJson(self::IN, $this->capture('idem-key-0001'))->assertOk();

        $retry->assertJsonPath('data.replayed', true);
        $this->assertSame($first->json('data.event.id'), $retry->json('data.event.id'));
        $this->assertSame($first->json('data.attendance.check_in_at'), $retry->json('data.attendance.check_in_at'));
        $this->assertSame(1, AttendanceEvent::query()->count());
    }

    public function test_an_idempotency_key_cannot_be_reused_for_another_action_or_employee(): void
    {
        [$user] = $this->makeStaff();
        $this->actingAsApi($user);
        $this->at('2026-09-30 08:00');
        $this->postJson(self::IN, $this->capture('shared-key-001'))->assertCreated();

        $this->postJson(self::OUT, $this->capture('shared-key-001'))
            ->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT');

        [$other] = $this->makeStaff();
        $this->actingAsApi($other);
        $this->postJson(self::IN, $this->capture('shared-key-001'))
            ->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT');
    }

    public function test_weekend_is_not_a_working_day_unless_overtime_is_approved(): void
    {
        [$user, $employee] = $this->makeStaff();
        $this->actingAsApi($user);
        $this->at('2026-10-03 09:00'); // Saturday

        $this->postJson(self::IN, $this->capture('sat-key-00001'))
            ->assertStatus(422)->assertJsonPath('error.code', 'NOT_A_WORKING_DAY');

        OvertimeRequest::query()->create([
            'employee_id' => $employee->id, 'work_date' => '2026-10-03', 'start_at' => '2026-10-03 02:00:00',
            'end_at' => '2026-10-03 06:00:00', 'requested_minutes' => 240, 'approved_minutes' => 240, 'reason' => 'Release',
            'status' => 'APPROVED',
        ]);

        $this->postJson(self::IN, $this->capture('sat-key-00002'))->assertCreated();
    }

    public function test_an_employee_without_a_shift_has_no_schedule(): void
    {
        [$user] = $this->makeStaff('employee', [], ['shift_id' => null]);
        $this->actingAsApi($user);
        $this->at('2026-09-30 08:00');

        $this->postJson(self::IN, $this->capture('noshift-key-1'))
            ->assertStatus(422)->assertJsonPath('error.code', 'NO_SCHEDULE');
    }

    public function test_selfie_is_required_by_policy_and_stored_privately(): void
    {
        $this->policy->update(['require_selfie' => true]);
        [$user] = $this->makeStaff();
        $this->actingAsApi($user);
        $this->at('2026-09-30 08:00');

        $this->postJson(self::IN, $this->capture('selfie-key-001'))
            ->assertStatus(422)->assertJsonPath('error.code', 'SELFIE_REQUIRED');

        $response = $this->post(self::IN, $this->capture('selfie-key-002') + ['selfie' => UploadedFile::fake()->image('me.jpg', 400, 400)], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.attendance.check_in.has_selfie', true);

        $event = AttendanceEvent::query()->firstOrFail();
        Storage::disk('local')->assertExists($event->selfie_path);
        $this->assertStringStartsWith('attendance-selfies/', $event->selfie_path);
        $this->assertStringNotContainsString($event->selfie_path, $response->getContent(), 'the storage path must never leak');
        $this->assertNotNull($event->selfie_meta['sha256']);
    }

    public function test_selfie_must_be_an_image_of_allowed_type(): void
    {
        $this->policy->update(['require_selfie' => true]);
        [$user] = $this->makeStaff();
        $this->actingAsApi($user);
        $this->at('2026-09-30 08:00');

        $this->post(self::IN, $this->capture('badfile-key-01') + ['selfie' => UploadedFile::fake()->create('x.pdf', 20, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->post(self::IN, $this->capture('bigfile-key-01') + ['selfie' => UploadedFile::fake()->image('big.jpg')->size(9000)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertSame(0, Attendance::query()->count());
    }

    public function test_offline_sync_uses_the_device_time_inside_the_allowed_window(): void
    {
        [$user] = $this->makeStaff();
        $this->actingAsApi($user);
        $this->at('2026-09-30 10:30'); // synced two hours after the tap

        $this->postJson(self::IN, $this->capture('off-key-000001', extra: [
            'is_offline_sync' => true, 'device_captured_at' => '2026-09-30T08:02:00+07:00',
        ]))->assertCreated()
            ->assertJsonPath('data.attendance.check_in_time', '08:02')
            ->assertJsonPath('data.attendance.check_in.time_source', 'device_offline')
            ->assertJsonPath('data.attendance.check_in.is_offline_sync', true);

        $event = AttendanceEvent::query()->firstOrFail();
        $this->assertSame('2026-09-30 03:30:00', $event->received_at->format('Y-m-d H:i:s'), 'server receive time is kept separately');
    }

    public function test_offline_events_older_than_the_window_or_from_the_future_are_refused(): void
    {
        [$user] = $this->makeStaff();
        $this->actingAsApi($user);
        $this->at('2026-09-30 10:00');

        $this->postJson(self::IN, $this->capture('old-off-000001', extra: ['is_offline_sync' => true, 'device_captured_at' => '2026-09-28T08:00:00+07:00']))
            ->assertStatus(422)->assertJsonPath('error.code', 'OFFLINE_EVENT_TOO_OLD');

        $this->postJson(self::IN, $this->capture('fut-off-000001', extra: ['is_offline_sync' => true, 'device_captured_at' => '2026-09-30T14:00:00+07:00']))
            ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_EVENT_TIME');

        $this->postJson(self::IN, $this->capture('notime-off-001', extra: ['is_offline_sync' => true]))
            ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_EVENT_TIME');
    }

    public function test_the_device_clock_is_ignored_for_normal_online_requests(): void
    {
        [$user] = $this->makeStaff();
        $this->actingAsApi($user);
        $this->at('2026-09-30 09:30');

        $this->postJson(self::IN, $this->capture('clock-key-001', extra: ['device_captured_at' => '2026-09-30T07:55:00+07:00']))
            ->assertCreated()
            ->assertJsonPath('data.attendance.check_in_time', '09:30')
            ->assertJsonPath('data.attendance.check_in.time_source', 'server');
    }

    public function test_overnight_shift_check_in_and_check_out_form_one_attendance(): void
    {
        $night = $this->makeShift('NGT', '22:00', '06:00', grace: 10, breakMinutes: 60);
        [$user] = $this->makeStaff('employee', [], ['shift_id' => $night->id]);
        $this->actingAsApi($user);

        $this->at('2026-09-29 22:05'); // Tuesday night
        $this->postJson(self::IN, $this->capture('ngt-in-key-01'))
            ->assertCreated()
            ->assertJsonPath('data.attendance.work_date', '2026-09-29');

        $this->at('2026-09-30 06:03'); // Wednesday morning, still the Tuesday shift
        $this->postJson(self::OUT, $this->capture('ngt-out-key-1'))
            ->assertCreated()
            ->assertJsonPath('data.attendance.work_date', '2026-09-29')
            ->assertJsonPath('data.attendance.status', 'CHECKED_OUT')
            ->assertJsonPath('data.attendance.worked_minutes', 418)
            ->assertJsonPath('data.attendance.is_late', false);

        $this->assertSame(1, Attendance::query()->count());
    }

    public function test_after_midnight_arrival_for_a_running_overnight_shift_belongs_to_yesterday(): void
    {
        $night = $this->makeShift('NGT', '22:00', '06:00', grace: 10);
        [$user] = $this->makeStaff('employee', [], ['shift_id' => $night->id]);
        $this->actingAsApi($user);

        $this->at('2026-09-30 01:00'); // arrives 3 hours late for the Tuesday night shift
        $this->postJson(self::IN, $this->capture('ngt-late-key1'))
            ->assertCreated()
            ->assertJsonPath('data.attendance.work_date', '2026-09-29')
            ->assertJsonPath('data.attendance.is_late', true)
            ->assertJsonPath('data.attendance.late_minutes', 180);
    }

    public function test_changing_the_shift_tomorrow_does_not_rewrite_yesterdays_schedule(): void
    {
        [$user, $employee] = $this->makeStaff();
        $this->actingAsApi($user);

        $this->at('2026-09-29 08:00');
        $this->postJson(self::IN, $this->capture('snap-key-0001'))->assertCreated();
        $schedule = AttendanceSchedule::query()->firstOrFail();
        $this->assertSame('REG shift', $schedule->shift_name);
        $this->assertSame(150, $schedule->location_radius_meters);

        // HR moves the employee to a different shift and a smaller site starting tomorrow.
        $late = $this->makeShift('LATE', '10:00', '19:00');
        $site = $this->location->replicate(['code'])->fill(['code' => 'SMALL', 'radius_meters' => 30, 'name' => 'Small Site']);
        $site->save();
        EmployeeAssignment::query()->where('employee_id', $employee->id)->update(['effective_to' => '2026-09-29']);
        EmployeeAssignment::query()->create([
            'employee_id' => $employee->id, 'department_id' => $this->department->id, 'position_id' => $this->position->id,
            'work_location_id' => $site->id, 'shift_id' => $late->id, 'effective_from' => '2026-09-30',
        ]);

        $schedule->refresh();
        $this->assertSame('REG shift', $schedule->shift_name);
        $this->assertSame(150, $schedule->location_radius_meters);
        $this->assertSame('Head Office', $schedule->location_name);
        $this->assertSame('01:00:00', $schedule->expected_start_at->format('H:i:s'));

        $this->at('2026-09-30 10:00');
        $this->postJson(self::IN, $this->capture('snap-key-0002', -6.9218, 107.6071))->assertCreated();
        $this->assertSame(2, AttendanceSchedule::query()->count());
        $this->assertSame('LATE shift', AttendanceSchedule::query()->orderByDesc('work_date')->first()->shift_name);
    }

    public function test_today_endpoint_reports_the_state_the_home_screen_needs(): void
    {
        [$user] = $this->makeStaff();
        $this->actingAsApi($user);

        $this->at('2026-09-30 07:00');
        $this->getJson('/api/v1/attendance/today')
            ->assertOk()
            ->assertJsonPath('data.state', 'NOT_CHECKED_IN')
            ->assertJsonPath('data.can_check_in', true)
            ->assertJsonPath('data.can_check_out', false)
            ->assertJsonPath('data.schedule.start_time', '08:00')
            ->assertJsonPath('data.schedule.location.name', 'Head Office')
            ->assertJsonPath('data.timezone', 'Asia/Jakarta');
        $this->assertSame(0, AttendanceSchedule::query()->count(), 'reading today must not write');

        $this->at('2026-09-30 08:00');
        $this->postJson(self::IN, $this->capture('today-key-0001'))->assertCreated();

        $this->at('2026-09-30 09:30');
        $this->getJson('/api/v1/attendance/today')
            ->assertJsonPath('data.state', 'CHECKED_IN')
            ->assertJsonPath('data.can_check_out', true)
            ->assertJsonPath('data.elapsed_minutes', 90);

        $this->at('2026-09-30 17:00');
        $this->postJson(self::OUT, $this->capture('today-key-0002'))->assertCreated();

        $this->at('2026-10-03 09:00'); // Saturday
        $this->getJson('/api/v1/attendance/today')
            ->assertJsonPath('data.state', 'NOT_WORKING_DAY')
            ->assertJsonPath('data.can_check_in', false);
    }

    public function test_a_forgotten_open_check_in_does_not_trap_the_employee_in_a_phantom_shift(): void
    {
        [$user] = $this->makeStaff();
        $this->actingAsApi($user);

        $this->at('2026-09-28 08:00');
        $this->postJson(self::IN, $this->capture('forgot-key-0001'))->assertCreated();

        // Two days later the old session can no longer be closed...
        $this->at('2026-09-30 07:30');
        $this->postJson(self::OUT, $this->capture('forgot-out-0001'))->assertStatus(409)->assertJsonPath('error.code', 'CHECK_OUT_TOO_LATE');

        // ...so Home reports a normal new day, plus the stale record so the app can offer a correction.
        $this->getJson('/api/v1/attendance/today')
            ->assertOk()
            ->assertJsonPath('data.state', 'NOT_CHECKED_IN')
            ->assertJsonPath('data.can_check_in', true)
            ->assertJsonPath('data.elapsed_minutes', null)
            ->assertJsonPath('data.stale_attendance.work_date', '2026-09-28')
            ->assertJsonPath('data.stale_attendance.status', 'CHECKED_IN');

        $this->at('2026-09-30 08:00');
        $this->postJson(self::IN, $this->capture('forgot-key-0002'))->assertCreated()->assertJsonPath('data.attendance.work_date', '2026-09-30');
        $this->getJson('/api/v1/attendance/today')->assertJsonPath('data.state', 'CHECKED_IN');
    }

    public function test_today_reports_blocked_for_terminated_employees(): void
    {
        [$user, $employee] = $this->makeStaff();
        $employee->update(['status' => EmployeeStatus::Terminated->value]);
        $this->actingAsApi($user);
        $this->at('2026-09-30 08:00');

        $this->getJson('/api/v1/attendance/today')
            ->assertJsonPath('data.state', 'BLOCKED')
            ->assertJsonPath('data.blocked_reason', 'EMPLOYEE_TERMINATED')
            ->assertJsonPath('data.can_check_in', false);
    }

    public function test_check_in_and_out_are_audited_without_the_selfie_path(): void
    {
        $this->policy->update(['require_selfie' => true]);
        [$user] = $this->makeStaff();
        $this->actingAsApi($user);
        $this->at('2026-09-30 08:00');

        $this->post(self::IN, $this->capture('audit-key-0001') + ['selfie' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])->assertCreated();

        $activity = Activity::query()->where('action', 'attendance.check_in')->firstOrFail();
        $this->assertSame($user->id, $activity->actor_id);
        $this->assertStringNotContainsString('selfie', json_encode($activity->new_values));
        $this->assertNotNull($activity->ip_address);
    }
}
