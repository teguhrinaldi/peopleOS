<?php

namespace Tests\Feature\Admin;

use App\Models\Activity;
use App\Models\AttendancePolicy;
use App\Models\AttendanceSchedule;
use App\Models\Holiday;
use App\Models\WorkingCalendar;
use App\Models\WorkingCalendarAssignment;
use App\Models\WorkLocation;

class CalendarAndLocationTest extends AdminTestCase
{
    // ---- Working calendars ----------------------------------------------------------------------

    public function test_a_calendar_saves_its_weekday_grid_and_only_one_default_exists(): void
    {
        $this->signIn('hr');
        $old = WorkingCalendar::query()->where('is_default', true)->firstOrFail();

        $this->post('/admin/shifts/calendars', ['name' => 'Six-day week', 'is_default' => 1, 'days' => [1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1, 7 => 0]])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $six = WorkingCalendar::query()->where('name', 'Six-day week')->firstOrFail();
        $this->assertTrue($six->is_default);
        $this->assertFalse($old->fresh()->is_default);
        $this->assertSame(1, WorkingCalendar::query()->where('is_default', true)->count());
        $this->assertTrue($six->load('days')->isWorkingDay(6));
        $this->assertFalse($six->isWorkingDay(7));
        $this->assertSame('Mon, Tue, Wed, Thu, Fri, Sat', Activity::query()->where('action', 'working_calendar.created')->firstOrFail()->new_values['working_days']);
    }

    public function test_the_default_cannot_be_switched_off_or_deleted(): void
    {
        $this->signIn('hr');
        $default = WorkingCalendar::query()->where('is_default', true)->firstOrFail();

        $this->put("/admin/shifts/calendars/{$default->id}", ['name' => $default->name, 'is_default' => 0, 'days' => [1 => 1]])->assertSessionHasErrors('is_default');
        $this->from('/admin/shifts/calendars')->delete("/admin/shifts/calendars/{$default->id}")->assertSessionHas('error');
        $this->assertNotSoftDeleted('working_calendars', ['id' => $default->id]);
    }

    public function test_calendar_assignments_are_date_ranged_without_overlap(): void
    {
        $this->signIn('hr');
        $employee = $this->person('Weekend Wes');
        $calendar = WorkingCalendar::query()->create(['company_id' => $this->company->id, 'name' => 'Weekend']);

        $this->post('/admin/shifts/calendars/assignments', ['employee_id' => $employee->id, 'working_calendar_id' => $calendar->id, 'effective_from' => '2026-10-01'])->assertSessionHasNoErrors();
        $this->post('/admin/shifts/calendars/assignments', ['employee_id' => $employee->id, 'working_calendar_id' => $calendar->id, 'effective_from' => '2026-11-01'])
            ->assertSessionHasErrors('effective_from');
        $this->post('/admin/shifts/calendars/assignments', ['employee_id' => $employee->id, 'working_calendar_id' => $calendar->id, 'effective_from' => '2026-09-01'])
            ->assertSessionHasErrors('effective_from');

        $this->from('/admin/shifts/calendars')->delete("/admin/shifts/calendars/{$calendar->id}")->assertSessionHas('error'); // still assigned
        $assignment = WorkingCalendarAssignment::query()->firstOrFail();
        $this->delete("/admin/shifts/calendars/assignments/{$assignment->id}")->assertSessionHas('success');
        $this->assertNull(WorkingCalendarAssignment::query()->find($assignment->id), 'an upcoming assignment is removed');
        $this->delete("/admin/shifts/calendars/{$calendar->id}")->assertSessionHas('success');
    }

    // ---- Holidays -------------------------------------------------------------------------------

    public function test_holidays_are_unique_per_date_and_filtered_by_year(): void
    {
        $this->signIn('hr');

        $this->post('/admin/shifts/holidays', ['date' => '2026-12-25', 'name' => 'Christmas Day', 'type' => 'national'])->assertSessionHasNoErrors();
        $this->post('/admin/shifts/holidays', ['date' => '2026-12-25', 'name' => 'Dup', 'type' => 'company'])->assertSessionHasErrors(['date' => 'There is already a holiday on this date.']);
        $this->post('/admin/shifts/holidays', ['date' => '2027-01-01', 'name' => "New Year's Day", 'type' => 'national'])->assertSessionHasNoErrors();
        $this->post('/admin/shifts/holidays', ['date' => '2027-02-01', 'name' => 'X', 'type' => 'bogus'])->assertSessionHasErrors('type');

        $this->get('/admin/shifts/holidays?year=2026')->assertOk()->assertSee('Christmas Day')->assertDontSee('New Year');
        $this->get('/admin/shifts/holidays?year=2027')->assertOk()->assertSee('New Year')->assertDontSee('Christmas Day');
        $this->assertTrue(Activity::query()->where('action', 'holiday.created')->exists());
    }

    public function test_a_holiday_frozen_into_a_schedule_keeps_its_date(): void
    {
        $this->signIn('hr');
        $holiday = Holiday::query()->create(['company_id' => $this->company->id, 'date' => '2026-09-28', 'name' => 'Past Day', 'type' => 'company']);
        $attendance = $this->attendance($this->person('Someone'), '2026-09-28');
        AttendanceSchedule::query()->whereKey($attendance->attendance_schedule_id)->update(['holiday_id' => $holiday->id]);

        $this->from('/admin/shifts/holidays')->put("/admin/shifts/holidays/{$holiday->id}", ['date' => '2026-09-27', 'name' => 'Past Day', 'type' => 'company'])->assertSessionHas('error');
        $this->put("/admin/shifts/holidays/{$holiday->id}", ['date' => '2026-09-28', 'name' => 'Renamed Day', 'type' => 'company'])->assertSessionHas('success');
        $this->from('/admin/shifts/holidays')->delete("/admin/shifts/holidays/{$holiday->id}")->assertSessionHas('error');

        $this->assertSame('2026-09-28', $holiday->fresh()->date->toDateString());
        $this->assertSame('Renamed Day', $holiday->fresh()->name);
    }

    // ---- Work locations -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function location(array $overrides = []): array
    {
        return array_merge(['code' => 'jkt', 'name' => 'Jakarta Office', 'branch_id' => $this->branch->id, 'attendance_policy_id' => $this->policy->id,
            'latitude' => '-6.2088', 'longitude' => '106.8456', 'radius_meters' => 120, 'is_active' => 1], $overrides);
    }

    public function test_location_coordinates_and_radius_are_validated(): void
    {
        $this->signIn('hr');

        $this->post('/admin/work-locations', $this->location(['latitude' => 91]))->assertSessionHasErrors(['latitude' => 'Latitude must be between -90 and 90.']);
        $this->post('/admin/work-locations', $this->location(['longitude' => -180.5]))->assertSessionHasErrors('longitude');
        $this->post('/admin/work-locations', $this->location(['latitude' => 'north']))->assertSessionHasErrors('latitude');
        $this->post('/admin/work-locations', $this->location(['radius_meters' => 5]))->assertSessionHasErrors('radius_meters');
        $this->post('/admin/work-locations', $this->location(['radius_meters' => 5001]))->assertSessionHasErrors('radius_meters');
        $this->post('/admin/work-locations', $this->location(['radius_meters' => 12.5]))->assertSessionHasErrors('radius_meters');
        $this->post('/admin/work-locations', $this->location(['code' => 'HO']))->assertSessionHasErrors('code');
        $this->post('/admin/work-locations', $this->location(['attendance_policy_id' => 99999, 'branch_id' => 99999]))->assertSessionHasErrors(['attendance_policy_id', 'branch_id']);
        $this->assertSame(1, WorkLocation::query()->count());

        $this->post('/admin/work-locations', $this->location())->assertRedirect('/admin/work-locations')->assertSessionHas('success');
        $jakarta = WorkLocation::query()->where('code', 'JKT')->firstOrFail();
        $this->assertEqualsWithDelta(-6.2088, $jakarta->latitude, 0.0000001);
        $this->assertSame(120, $jakarta->radius_meters);
        $this->assertTrue(Activity::query()->where('action', 'work_location.created')->exists());
    }

    public function test_editing_a_location_never_changes_frozen_schedules(): void
    {
        $this->signIn('hr');
        $schedule = $this->attendance($this->person('Worker Wu'), '2026-09-29')->schedule;

        $this->put("/admin/work-locations/{$this->location->id}", $this->location(['code' => 'HO', 'name' => 'Moved Office', 'latitude' => 1.5, 'longitude' => 2.5, 'radius_meters' => 500]))
            ->assertSessionHasNoErrors();

        $this->assertSame(500, $this->location->fresh()->radius_meters);
        $fresh = AttendanceSchedule::query()->find($schedule->id);
        $this->assertSame(150, $fresh->location_radius_meters);
        $this->assertEqualsWithDelta(-6.9218, $fresh->location_latitude, 0.0000001);
        $this->assertSame('Head Office', $fresh->location_name);
    }

    public function test_a_location_in_use_cannot_be_deleted(): void
    {
        $this->signIn('hr');
        $this->person('Placed Pat');

        $this->from('/admin/work-locations')->delete("/admin/work-locations/{$this->location->id}")->assertSessionHas('error');
        $this->assertNotSoftDeleted('work_locations', ['id' => $this->location->id]);
    }

    public function test_policies_keep_a_single_default_and_protect_what_is_in_use(): void
    {
        $this->signIn('hr');

        $this->post('/admin/work-locations/policies', ['name' => 'Strict', 'is_default' => 1, 'require_gps' => 1, 'geofence_mode' => 'review', 'require_selfie' => 1,
            'max_gps_accuracy_meters' => 50, 'reject_mock_location' => 1, 'offline_max_age_minutes' => 0])->assertSessionHasNoErrors();
        $strict = AttendancePolicy::query()->where('name', 'Strict')->firstOrFail();
        $this->assertTrue($strict->is_default);
        $this->assertFalse($this->policy->fresh()->is_default);
        $this->assertTrue($strict->require_selfie);

        $this->post('/admin/work-locations/policies', ['name' => 'Bad', 'geofence_mode' => 'maybe', 'max_gps_accuracy_meters' => 1, 'offline_max_age_minutes' => -1])
            ->assertSessionHasErrors(['geofence_mode', 'max_gps_accuracy_meters', 'offline_max_age_minutes']);

        $this->from('/admin/work-locations/policies')->delete("/admin/work-locations/policies/{$strict->id}")->assertSessionHas('error'); // default
        $this->from('/admin/work-locations/policies')->delete("/admin/work-locations/policies/{$this->policy->id}")->assertSessionHas('error'); // used by a location

        $spare = AttendancePolicy::query()->create(['company_id' => $this->company->id, 'name' => 'Spare', 'geofence_mode' => 'off']);
        $this->delete("/admin/work-locations/policies/{$spare->id}")->assertSessionHas('success');
        $this->assertSoftDeleted('attendance_policies', ['id' => $spare->id]);
    }

    public function test_the_location_pages_render_the_geofence_diagram(): void
    {
        $this->signIn('hr');

        $this->get("/admin/work-locations/{$this->location->id}/edit")->assertOk()->assertSee('Geofence preview')->assertSee('Attendance already recorded keeps the geofence');
        $this->get('/admin/work-locations')->assertOk()->assertSee('Head Office')->assertSee('150 m');
    }
}
