<?php

namespace Tests\Feature\Admin;

use App\Models\Activity;
use App\Models\Department;
use App\Models\Employee;
use App\Models\WorkLocation;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

class AttendanceBoardTest extends AdminTestCase
{
    /** @return list<string> employee names on the board */
    private function names(TestResponse $response): array
    {
        return $response->assertOk()->viewData('records')->getCollection()->map(fn ($a) => $a->employee->full_name)->values()->all();
    }

    /** @return list<list<string>> */
    private function csv(TestResponse $response): array
    {
        $body = ltrim($response->assertOk()->streamedContent(), "\xEF\xBB\xBF");

        return array_map('str_getcsv', array_values(array_filter(explode("\n", $body))));
    }

    /** @return array{0: Employee, 1: Employee, 2: Employee} [my report, another manager's report, nobody's] */
    private function teams(Employee $me): array
    {
        [, $otherManager] = $this->makeStaff('manager');

        return [
            $this->person('Report Rita', [], ['manager_id' => $me->id]),
            $this->person('Other Olga', [], ['manager_id' => $otherManager->id]),
            $this->person('Loose Luke'),
        ];
    }

    public function test_the_board_defaults_to_today_and_hr_sees_everyone(): void
    {
        $this->signIn('hr');
        $a = $this->person('Anna Today');
        $this->attendance($a, '2026-09-30');
        $this->attendance($a, '2026-09-29');
        $this->attendance($this->person('Ben Today'), '2026-09-30', late: 25);

        $response = $this->get('/admin/attendance');

        $this->assertSame(['Anna Today', 'Ben Today'], $this->names($response));
        $this->assertSame(['records' => 2, 'late' => 1, 'early' => 0, 'review' => 0, 'worked_minutes' => 0], $response->viewData('summary'));
        $response->assertSee('+15m');
    }

    public function test_a_manager_only_sees_direct_reports(): void
    {
        [, $me] = $this->signIn('manager');
        [$mine, $other, $loose] = $this->teams($me);
        foreach ([$mine, $other, $loose] as $e) {
            $this->attendance($e, '2026-09-30');
        }

        $this->assertSame(['Report Rita'], $this->names($this->get('/admin/attendance')));
        $this->assertSame([], $this->names($this->get("/admin/attendance?employee_id={$other->id}")), 'an id in the URL cannot widen the scope');
    }

    public function test_filters_by_date_range_department_on_the_work_date_location_status_and_review(): void
    {
        $this->signIn('hr');
        $sales = Department::query()->create(['company_id' => $this->company->id, 'code' => 'SAL', 'name' => 'Sales']);
        $site = WorkLocation::query()->create(['company_id' => $this->company->id, 'code' => 'SITE', 'name' => 'Site B', 'latitude' => 0, 'longitude' => 0, 'radius_meters' => 50]);

        // Mover was in Marketing until 27 Sep, then Sales.
        $mover = $this->person('Mover Max', [], ['effective_from' => '2025-01-01', 'effective_to' => '2026-09-27']);
        $mover->assignments()->create(['department_id' => $sales->id, 'position_id' => $this->position->id, 'effective_from' => '2026-09-28']);
        $this->attendance($mover, '2026-09-25');
        $this->attendance($mover, '2026-09-29', late: 30);
        $flagged = $this->attendance($this->person('Flag Fay'), '2026-09-29', status: 'PENDING', review: true, schedule: ['work_location_id' => $site->id, 'location_name' => 'Site B']);
        $this->assertNotNull($flagged);

        $range = 'from=2026-09-20&to=2026-09-30';
        $this->assertCount(3, $this->names($this->get("/admin/attendance?{$range}")));
        $this->assertSame(['Mover Max'], $this->names($this->get("/admin/attendance?{$range}&department_id={$sales->id}")), 'only the day he was already in Sales');
        $this->assertSame('2026-09-29', $this->get("/admin/attendance?{$range}&department_id={$sales->id}")->viewData('records')->first()->work_date->toDateString());
        $this->assertSame(['Flag Fay'], $this->names($this->get("/admin/attendance?{$range}&work_location_id={$site->id}")));
        $this->assertSame(['Mover Max'], $this->names($this->get("/admin/attendance?{$range}&status=LATE")));
        $this->assertSame(['Flag Fay'], $this->names($this->get("/admin/attendance?{$range}&status=PENDING")));
        $this->assertSame(['Flag Fay'], $this->names($this->get('/admin/attendance?review=1')), 'review without dates covers all dates');
        $this->get('/admin/attendance?status=NOPE')->assertSessionHasErrors('status');
        $this->get('/admin/attendance?from=2026-09-30&to=2026-09-01')->assertSessionHasErrors('to');
    }

    public function test_the_board_lists_who_has_not_checked_in_on_a_working_day(): void
    {
        $this->signIn('hr');
        $this->attendance($this->person('Here Hal'), '2026-09-30');
        $this->person('Missing Mo');
        $this->person('Pending Pip', ['status' => 'PENDING']);

        $missing = $this->get('/admin/attendance')->assertOk()->viewData('missing')->pluck('full_name')->all();

        $this->assertContains('Missing Mo', $missing);
        $this->assertNotContains('Here Hal', $missing);
        $this->assertNotContains('Pending Pip', $missing);
        $this->assertNull($this->get('/admin/attendance?from=2026-10-03')->viewData('missing'), 'Saturday: nobody is expected');
    }

    public function test_csv_export_is_scoped_filtered_audited_and_formula_safe(): void
    {
        [$manager, $me] = $this->signIn('manager');
        [$mine, $other] = $this->teams($me);
        $mine->update(['full_name' => '=HYPERLINK("http://evil")']);
        $this->attendance($mine, '2026-09-29', late: 20);
        $this->attendance($other, '2026-09-29');

        $rows = $this->csv($this->get('/admin/attendance/export?from=2026-09-29'));

        $this->assertSame('work_date', $rows[0][0]);
        $this->assertCount(2, $rows, 'header + my one report');
        $this->assertSame('2026-09-29', $rows[1][0]);
        $this->assertSame("'=HYPERLINK(\"http://evil\")", $rows[1][2]);
        $this->assertSame('Marketing', $rows[1][3]);
        $this->assertSame('08:20', $rows[1][6]);
        $this->assertTrue(Activity::query()->where('action', 'attendance.exported')->where('actor_id', $manager->id)->exists());

        $this->signIn('hr');
        $this->assertCount(3, $this->csv($this->get('/admin/attendance/export?from=2026-09-29')));
        $this->assertCount(1, $this->csv($this->get('/admin/attendance/export?from=2026-09-28')), 'header only');
    }

    public function test_record_detail_follows_the_attendance_policy(): void
    {
        [, $me] = $this->signIn('manager');
        [$mine, $other] = $this->teams($me);
        $myRecord = $this->attendance($mine, '2026-09-29');
        $this->event($myRecord);
        $otherRecord = $this->attendance($other, '2026-09-29');

        $this->get("/admin/attendance/{$myRecord->id}")->assertOk()->assertSee('Schedule snapshot')->assertSee('Report Rita')->assertSee('14 m');
        $this->get("/admin/attendance/{$otherRecord->id}")->assertForbidden();
    }

    public function test_selfies_stream_only_to_viewers_allowed_by_the_record_policy(): void
    {
        Storage::fake(config('peopleos.attendance.selfie_disk'));
        Storage::disk(config('peopleos.attendance.selfie_disk'))->put('attendance-selfies/s1.jpg', 'JPEGDATA');

        [$manager, $me] = $this->makeStaff('manager');
        [$otherManager] = $this->makeStaff('manager');
        [$hr] = $this->makeStaff('hr');
        $report = $this->person('Report Rita', [], ['manager_id' => $me->id]);
        $record = $this->attendance($report, '2026-09-29');
        $event = $this->event($record, extra: ['selfie_path' => 'attendance-selfies/s1.jpg']);
        $noSelfie = $this->event($record, 'CHECK_OUT');
        $url = "/admin/attendance/events/{$event->id}/selfie";

        $this->actingAs($hr)->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame('JPEGDATA', $this->actingAs($hr)->get($url)->streamedContent());
        $this->actingAs($manager)->get($url)->assertOk();
        $this->actingAs($otherManager)->get($url)->assertForbidden();
        $this->actingAs($hr)->get("/admin/attendance/events/{$noSelfie->id}/selfie")->assertNotFound();

        [$employeeUser] = $this->makeStaff('employee');
        $this->actingAs($employeeUser)->get($url)->assertForbidden();
    }
}
