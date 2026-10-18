<?php

namespace Tests\Feature\Admin;

use App\Enums\AttendanceStatus;
use App\Enums\RequestStatus;
use App\Models\Activity;

class ApprovalQueuesTest extends AdminTestCase
{
    // ---- Flagged attendance review --------------------------------------------------------------

    public function test_hr_approves_or_rejects_flagged_attendance_and_rejecting_needs_a_note(): void
    {
        [$hr] = $this->signIn('hr');
        $a = $this->attendance($this->person('Flag Fay'), '2026-09-29', status: 'PENDING', review: true);
        $b = $this->attendance($this->person('Flag Finn'), '2026-09-29', status: 'PENDING', review: true);

        $this->get("/admin/attendance/{$a->id}")->assertOk()->assertSee('Approve')->assertSee('Reject');

        $this->post("/admin/attendance/{$b->id}/review", ['decision' => 'reject', 'note' => ''])->assertSessionHasErrors(['note' => 'Tell the employee why this is rejected.']);
        $this->assertSame(AttendanceStatus::Pending, $b->fresh()->status);

        $this->post("/admin/attendance/{$a->id}/review", ['decision' => 'approve'])->assertRedirect("/admin/attendance/{$a->id}");
        $this->assertSame(AttendanceStatus::CheckedIn, $a->fresh()->status);
        $this->assertFalse($a->fresh()->needs_review);

        $this->post("/admin/attendance/{$b->id}/review", ['decision' => 'reject', 'note' => 'Outside the site'])->assertSessionHas('success');
        $this->assertSame(AttendanceStatus::Rejected, $b->fresh()->status);
        $this->assertSame('Outside the site', $b->fresh()->review_note);

        $this->assertTrue(Activity::query()->where('action', 'attendance.review_approved')->where('actor_id', $hr->id)->exists());
        $this->assertTrue(Activity::query()->where('action', 'attendance.review_rejected')->where('actor_id', $hr->id)->exists());

        // Already decided: the domain's 409 becomes a friendly message.
        $this->from("/admin/attendance/{$a->id}")->post("/admin/attendance/{$a->id}/review", ['decision' => 'approve'])
            ->assertSessionHas('error', 'This attendance is not awaiting review.');
    }

    public function test_nobody_reviews_their_own_flagged_attendance(): void
    {
        [, $me] = $this->signIn('hr');
        $mine = $this->attendance($me, '2026-09-29', status: 'PENDING', review: true);

        $this->get("/admin/attendance/{$mine->id}")->assertOk()->assertDontSee('review-form');
        $this->post("/admin/attendance/{$mine->id}/review", ['decision' => 'approve'])->assertForbidden();
        $this->assertSame(AttendanceStatus::Pending, $mine->fresh()->status);
    }

    // ---- Corrections ----------------------------------------------------------------------------

    public function test_the_corrections_queue_hides_own_requests_and_is_team_scoped_for_managers(): void
    {
        [$managerUser, $me] = $this->signIn('manager');
        [, $otherManager] = $this->makeStaff('manager');
        $mine = $this->person('Report Rita', [], ['manager_id' => $me->id]);
        $theirs = $this->person('Other Olga', [], ['manager_id' => $otherManager->id]);

        $this->correctionFor($this->attendance($mine, '2026-09-29'));
        $this->correctionFor($this->attendance($theirs, '2026-09-29'));
        $this->correctionFor($this->attendance($me, '2026-09-29'), $managerUser);

        $names = fn () => $this->get('/admin/attendance/corrections')->assertOk()->viewData('corrections')->getCollection()->map(fn ($c) => $c->employee->full_name)->all();
        $this->assertSame(['Report Rita'], $names());

        $this->signIn('hr');
        $this->assertEqualsCanonicalizing(['Report Rita', 'Other Olga', $me->full_name], $names());
    }

    public function test_approving_a_correction_applies_the_times_through_the_domain_service(): void
    {
        [$hr] = $this->signIn('hr');
        $attendance = $this->attendance($this->person('Forgetful Fred'), '2026-09-29');
        $correction = $this->correctionFor($attendance);

        $this->post("/admin/attendance/corrections/{$correction->id}/decision", ['decision' => 'approve', 'note' => 'OK'])
            ->assertRedirect('/admin/attendance/corrections')->assertSessionHas('success');

        $this->assertSame(RequestStatus::Approved, $correction->fresh()->status);
        $fresh = $attendance->fresh();
        $this->assertSame(AttendanceStatus::Corrected, $fresh->status);
        $this->assertSame('10:00', $fresh->check_out_at->format('H:i'), '17:00 Jakarta is 10:00 UTC');
        $this->assertGreaterThan(0, $fresh->worked_minutes);
        $this->assertTrue(Activity::query()->where('action', 'attendance_correction.approved')->where('actor_id', $hr->id)->exists());

        $this->get('/admin/attendance/corrections?tab=decided')->assertOk()->assertSee('Forgetful Fred')->assertSee('Approved');
    }

    public function test_rejecting_a_correction_needs_a_note(): void
    {
        $this->signIn('hr');
        $correction = $this->correctionFor($this->attendance($this->person('Late Lou'), '2026-09-29'));
        $url = "/admin/attendance/corrections/{$correction->id}/decision";

        $this->post($url, ['decision' => 'reject'])->assertSessionHasErrors('note');
        $this->post($url, ['decision' => 'reject', 'note' => '   '])->assertSessionHasErrors('note');
        $this->post($url, ['decision' => 'maybe'])->assertSessionHasErrors('decision');
        $this->assertSame(RequestStatus::Pending, $correction->fresh()->status);

        $this->post($url, ['decision' => 'reject', 'note' => 'The gate log shows 16:00'])->assertSessionHas('success');
        $this->assertSame(RequestStatus::Rejected, $correction->fresh()->status);
        $this->assertSame('The gate log shows 16:00', $correction->fresh()->review_note);
    }

    public function test_own_and_out_of_team_corrections_cannot_be_decided(): void
    {
        [$managerUser, $me] = $this->signIn('manager');
        [, $otherManager] = $this->makeStaff('manager');
        $own = $this->correctionFor($this->attendance($me, '2026-09-29'), $managerUser);
        $theirs = $this->correctionFor($this->attendance($this->person('Other Olga', [], ['manager_id' => $otherManager->id]), '2026-09-29'));

        $this->post("/admin/attendance/corrections/{$own->id}/decision", ['decision' => 'approve'])->assertForbidden();
        $this->post("/admin/attendance/corrections/{$theirs->id}/decision", ['decision' => 'approve'])->assertForbidden();

        $this->assertSame(RequestStatus::Pending, $own->fresh()->status);
        $this->assertSame(RequestStatus::Pending, $theirs->fresh()->status);
    }

    // ---- Overtime -------------------------------------------------------------------------------

    public function test_the_overtime_queue_is_scoped_and_hides_own_requests(): void
    {
        [, $me] = $this->signIn('manager');
        [, $otherManager] = $this->makeStaff('manager');
        $this->overtimeFor($this->person('Report Rita', [], ['manager_id' => $me->id]));
        $this->overtimeFor($this->person('Other Olga', [], ['manager_id' => $otherManager->id]));
        $this->overtimeFor($me);

        $response = $this->get('/admin/overtime')->assertOk();
        $this->assertSame(['Report Rita'], $response->viewData('requests')->getCollection()->map(fn ($r) => $r->employee->full_name)->all());
        $response->assertSee('Approve');
    }

    public function test_overtime_approval_respects_the_requested_minutes(): void
    {
        [$hr] = $this->signIn('hr');
        $request = $this->overtimeFor($this->person('Busy Bea'), minutes: 120);
        $url = "/admin/overtime/{$request->id}/decision";

        $this->post($url, ['decision' => 'approve', 'approved_minutes' => 150])->assertSessionHasErrors(['approved_minutes' => 'Approved minutes cannot exceed the 120 minutes requested.']);
        $this->post($url, ['decision' => 'approve', 'approved_minutes' => 0])->assertSessionHasErrors('approved_minutes');
        $this->assertSame(RequestStatus::Pending, $request->fresh()->status);

        $this->post($url, ['decision' => 'approve', 'approved_minutes' => 90])->assertRedirect('/admin/overtime')->assertSessionHas('success');
        $this->assertSame(RequestStatus::Approved, $request->fresh()->status);
        $this->assertSame(90, $request->fresh()->approved_minutes);
        $this->assertTrue(Activity::query()->where('action', 'overtime.approved')->where('actor_id', $hr->id)->exists());

        $full = $this->overtimeFor($this->person('Full Flo'), '2026-09-28', 60);
        $this->post("/admin/overtime/{$full->id}/decision", ['decision' => 'approve'])->assertSessionHas('success');
        $this->assertSame(60, $full->fresh()->approved_minutes, 'defaults to the full request');

        $this->get('/admin/overtime?tab=history')->assertOk()->assertSee('Busy Bea')->assertSee('1h 30m');
    }

    public function test_overtime_rejection_needs_a_note_and_decided_requests_are_final(): void
    {
        $this->signIn('hr');
        $request = $this->overtimeFor($this->person('Busy Bea'));
        $url = "/admin/overtime/{$request->id}/decision";

        $this->post($url, ['decision' => 'reject'])->assertSessionHasErrors('note');
        $this->post($url, ['decision' => 'reject', 'note' => 'Not pre-approved'])->assertSessionHas('success');
        $this->assertSame(RequestStatus::Rejected, $request->fresh()->status);

        $this->from('/admin/overtime')->post($url, ['decision' => 'approve'])->assertSessionHas('error', 'This request has already been decided.');
        $this->assertSame(RequestStatus::Rejected, $request->fresh()->status);
    }

    public function test_managers_cannot_decide_overtime_outside_their_team(): void
    {
        [, $me] = $this->signIn('manager');
        [, $otherManager] = $this->makeStaff('manager');
        $theirs = $this->overtimeFor($this->person('Other Olga', [], ['manager_id' => $otherManager->id]));
        $own = $this->overtimeFor($me);

        $this->post("/admin/overtime/{$theirs->id}/decision", ['decision' => 'approve'])->assertForbidden();
        $this->post("/admin/overtime/{$own->id}/decision", ['decision' => 'approve'])->assertForbidden();
        $this->assertSame(RequestStatus::Pending, $theirs->fresh()->status);
    }

    public function test_dashboard_approval_cards_link_to_the_real_queues(): void
    {
        $this->signIn('hr');

        $this->get('/admin')->assertOk()
            ->assertSee(route('admin.attendance.corrections'), false)
            ->assertSee(route('admin.overtime'), false)
            ->assertSee(route('admin.attendance', ['review' => 1]), false);
    }
}
