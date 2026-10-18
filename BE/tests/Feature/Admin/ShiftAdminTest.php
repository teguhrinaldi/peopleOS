<?php

namespace Tests\Feature\Admin;

use App\Models\Activity;
use App\Models\AttendanceSchedule;
use App\Models\Shift;
use App\Models\ShiftAssignment;

class ShiftAdminTest extends AdminTestCase
{
    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'ngt', 'name' => 'Night Shift', 'start_time' => '22:00', 'end_time' => '06:00',
            'grace_late_minutes' => 10, 'grace_early_leave_minutes' => 5, 'is_active' => 1,
            'breaks' => [['name' => 'Midnight break', 'start_time' => '02:00', 'end_time' => '03:00', 'is_paid' => 0]],
        ], $overrides);
    }

    public function test_an_overnight_shift_gets_every_derived_number_from_the_server(): void
    {
        [$hr] = $this->signIn('hr');

        // A tampered form cannot choose the derived values.
        $this->post('/admin/shifts', $this->payload(['working_minutes' => 9999, 'crosses_midnight' => 0, 'break_minutes' => 0]))
            ->assertRedirect('/admin/shifts')->assertSessionHas('success');

        $shift = Shift::query()->where('code', 'NGT')->firstOrFail();
        $this->assertTrue($shift->crosses_midnight);
        $this->assertSame(420, $shift->working_minutes, '8h span minus 60m unpaid break');
        $this->assertSame(60, $shift->break_minutes);
        $this->assertSame('22:00:00', $shift->start_time);
        $this->assertSame(60, $shift->breaks()->first()->duration_minutes);

        $log = Activity::query()->where('action', 'shift.created')->where('entity_id', $shift->id)->firstOrFail();
        $this->assertSame($hr->id, $log->actor_id);
        $this->assertSame(420, $log->new_values['working_minutes']);
        $this->assertSame(['Midnight break 02:00-03:00 unpaid'], $log->new_values['breaks']);

        $this->get('/admin/shifts')->assertOk()->assertSee('+1')->assertSee('7h 00m');
    }

    public function test_paid_breaks_are_not_deducted_and_several_breaks_add_up(): void
    {
        $this->signIn('hr');

        $this->post('/admin/shifts', $this->payload(['code' => 'DAY', 'start_time' => '08:00', 'end_time' => '17:00', 'breaks' => [
            ['name' => 'Coffee', 'start_time' => '10:00', 'end_time' => '10:15', 'is_paid' => 1],
            ['name' => 'Lunch', 'start_time' => '12:00', 'end_time' => '13:00', 'is_paid' => 0],
            ['name' => 'Tea', 'start_time' => '15:00', 'end_time' => '15:30', 'is_paid' => 0],
        ]]))->assertSessionHasNoErrors();

        $shift = Shift::query()->where('code', 'DAY')->firstOrFail();
        $this->assertFalse($shift->crosses_midnight);
        $this->assertSame(90, $shift->break_minutes);
        $this->assertSame(450, $shift->working_minutes);
        $this->assertSame(3, $shift->breaks()->count());
    }

    public function test_breaks_must_sit_inside_the_shift_and_not_overlap(): void
    {
        $this->signIn('hr');

        $this->post('/admin/shifts', $this->payload(['breaks' => [['name' => 'Outside', 'start_time' => '07:00', 'end_time' => '07:30']]]))
            ->assertSessionHasErrors(['breaks.0.start_time' => 'The break must fall inside the shift.']);
        $this->post('/admin/shifts', $this->payload(['breaks' => [
            ['name' => 'A', 'start_time' => '01:00', 'end_time' => '02:00'],
            ['name' => 'B', 'start_time' => '01:30', 'end_time' => '02:30'],
        ]]))->assertSessionHasErrors('breaks');
        $this->post('/admin/shifts', $this->payload(['breaks' => [['name' => '', 'start_time' => '', 'end_time' => '02:30']]]))
            ->assertSessionHasErrors(['breaks.0.name', 'breaks.0.start_time']);

        $this->assertNull(Shift::query()->where('code', 'NGT')->first());
    }

    public function test_shift_validation(): void
    {
        $this->signIn('hr');

        $this->post('/admin/shifts', $this->payload(['start_time' => '08:00', 'end_time' => '08:00']))->assertSessionHasErrors('end_time');
        $this->post('/admin/shifts', $this->payload(['start_time' => '25:00']))->assertSessionHasErrors('start_time');
        $this->post('/admin/shifts', $this->payload(['code' => 'REG']))->assertSessionHasErrors(['code' => 'This code is already used by another shift.']);
        $this->post('/admin/shifts', $this->payload(['grace_late_minutes' => 999]))->assertSessionHasErrors('grace_late_minutes');
    }

    public function test_editing_a_shift_never_changes_frozen_schedules(): void
    {
        $this->signIn('hr');
        $employee = $this->person('Worker Wu');
        $past = $this->attendance($employee, '2026-09-29')->schedule;
        $before = $past->only(['expected_start_at', 'expected_end_at', 'expected_working_minutes', 'shift_name']);

        $this->put("/admin/shifts/{$this->shift->id}", $this->payload(['code' => 'REG', 'name' => 'Regular (new hours)', 'start_time' => '09:00', 'end_time' => '18:00', 'breaks' => []]))
            ->assertSessionHasNoErrors();

        $this->assertSame(540, $this->shift->fresh()->working_minutes);
        $this->assertEquals($before, AttendanceSchedule::query()->find($past->id)->only(array_keys($before)));
        $this->get("/admin/shifts/{$this->shift->id}/edit")->assertOk()->assertSee('Attendance already recorded keeps the schedule');

        $log = Activity::query()->where('action', 'shift.updated')->firstOrFail();
        $this->assertSame('08:00:00', $log->old_values['start_time']);
        $this->assertSame('09:00:00', $log->new_values['start_time']);
    }

    public function test_a_shift_in_use_cannot_be_deleted_but_an_unused_one_can(): void
    {
        $this->signIn('hr');
        $this->person('Placed Pat');
        $spare = $this->makeShift('SPR', '10:00', '18:00');

        $this->from('/admin/shifts')->delete("/admin/shifts/{$this->shift->id}")->assertSessionHas('error');
        $this->assertNotSoftDeleted('shifts', ['id' => $this->shift->id]);

        $this->delete("/admin/shifts/{$spare->id}")->assertSessionHas('success');
        $this->assertSoftDeleted('shifts', ['id' => $spare->id]);
        $this->assertTrue(Activity::query()->where('action', 'shift.deleted')->where('entity_id', $spare->id)->exists());
    }

    public function test_shift_overrides_reject_overlaps_and_past_starts(): void
    {
        $this->signIn('hr');
        $employee = $this->person('Rota Rae');
        $night = $this->makeShift('NT2', '22:00', '06:00');
        $url = '/admin/shifts/assignments';

        $this->post($url, ['employee_id' => $employee->id, 'shift_id' => $night->id, 'start_date' => '2026-10-05', 'end_date' => '2026-10-11'])->assertSessionHasNoErrors();
        $this->post($url, ['employee_id' => $employee->id, 'shift_id' => $night->id, 'start_date' => '2026-10-10', 'end_date' => '2026-10-20'])
            ->assertSessionHasErrors(['start_date' => 'This period overlaps another one for the same employee. End that one first.']);
        $this->post($url, ['employee_id' => $employee->id, 'shift_id' => $night->id, 'start_date' => '2026-10-01'])
            ->assertSessionHasErrors('start_date'); // open end overlaps the 5th-11th
        $this->post($url, ['employee_id' => $employee->id, 'shift_id' => $night->id, 'start_date' => '2026-09-20', 'end_date' => '2026-09-25'])
            ->assertSessionHasErrors('start_date');
        $this->post($url, ['employee_id' => $employee->id, 'shift_id' => $night->id, 'start_date' => '2026-10-12', 'end_date' => '2026-10-10'])
            ->assertSessionHasErrors('end_date');
        $this->post($url, ['employee_id' => $employee->id, 'shift_id' => $night->id, 'start_date' => '2026-10-12'])->assertSessionHasNoErrors();

        $this->assertSame(2, ShiftAssignment::query()->where('employee_id', $employee->id)->count());
        $this->assertSame(2, Activity::query()->where('action', 'shift_assignment.created')->count());
    }

    public function test_overrides_for_terminated_people_or_inactive_shifts_are_refused(): void
    {
        $this->signIn('hr');
        $gone = $this->person('Gone Gus', ['status' => 'TERMINATED', 'terminated_at' => '2026-06-30']);
        $retired = $this->makeShift('OLD', '07:00', '15:00');
        $retired->update(['is_active' => false]);

        $this->post('/admin/shifts/assignments', ['employee_id' => $gone->id, 'shift_id' => $this->shift->id, 'start_date' => '2026-10-01'])->assertSessionHasErrors('employee_id');
        $this->post('/admin/shifts/assignments', ['employee_id' => $this->person('Active Al')->id, 'shift_id' => $retired->id, 'start_date' => '2026-10-01'])->assertSessionHasErrors('shift_id');
    }

    public function test_an_upcoming_override_is_removed_and_a_running_one_only_ends_today(): void
    {
        [$hr] = $this->signIn('hr');
        $employee = $this->person('Rota Rae');
        $upcoming = ShiftAssignment::query()->create(['employee_id' => $employee->id, 'shift_id' => $this->shift->id, 'start_date' => '2026-10-10']);
        $running = ShiftAssignment::query()->create(['employee_id' => $employee->id, 'shift_id' => $this->shift->id, 'start_date' => '2026-09-01', 'end_date' => '2026-10-05']);
        $ended = ShiftAssignment::query()->create(['employee_id' => $employee->id, 'shift_id' => $this->shift->id, 'start_date' => '2026-08-01', 'end_date' => '2026-08-31']);

        $this->delete("/admin/shifts/assignments/{$upcoming->id}")->assertSessionHas('success');
        $this->assertNull(ShiftAssignment::query()->find($upcoming->id));

        $this->delete("/admin/shifts/assignments/{$running->id}")->assertSessionHas('success');
        $this->assertSame('2026-09-30', $running->fresh()->end_date->toDateString());

        $this->delete("/admin/shifts/assignments/{$ended->id}")->assertSessionHas('error');
        $this->assertSame('2026-08-31', $ended->fresh()->end_date->toDateString());

        $this->assertTrue(Activity::query()->where('action', 'shift_assignment.ended')->where('actor_id', $hr->id)->exists());
        $this->assertTrue(Activity::query()->where('action', 'shift_assignment.deleted')->exists());
        $this->get('/admin/shifts/assignments?state=past')->assertOk()->assertSee('Rota Rae');
    }
}
