<?php

namespace Tests\Feature\Admin;

use App\Models\AppNotification;
use App\Models\LeaveRequest;
use App\Models\User;

class NotificationBellTest extends Batch2TestCase
{
    private function note(User $user, string $title, array $extra = []): AppNotification
    {
        return AppNotification::query()->create($extra + ['user_id' => $user->id, 'type' => 'system.info', 'title' => $title]);
    }

    public function test_bell_shows_only_my_unread_count_and_latest_items(): void
    {
        [$me] = $this->signIn('hr');
        [$other] = $this->makeStaff('hr');
        $this->note($me, 'Mine one');
        $this->note($me, 'Mine two');
        $this->note($me, 'Already read', ['read_at' => now()]);
        $this->note($other, 'Secret for someone else');

        $html = $this->get(route('admin.dashboard'))->assertOk()->assertSee('Mine one')->assertSee('Mine two')->assertSee('Already read')->assertDontSee('Secret for someone else')->getContent();
        $this->assertStringContainsString('Notifications, 2 unread', $html);
        $this->assertStringContainsString('(2 unread)', $html);
    }

    public function test_empty_bell(): void
    {
        $this->signIn('hr');
        $this->get(route('admin.dashboard'))->assertSee('You are all caught up.')->assertDontSee('unread');
    }

    public function test_mark_read_and_mark_all_read_only_touch_my_rows(): void
    {
        [$me] = $this->signIn('hr');
        [$other] = $this->makeStaff('hr');
        $mine = $this->note($me, 'A');
        $this->note($me, 'B');
        $theirs = $this->note($other, 'C');

        $this->post(route('admin.notifications.read', $mine))->assertRedirect();
        $this->assertNotNull($mine->fresh()->read_at);

        $this->post(route('admin.notifications.read', $theirs))->assertNotFound();
        $this->assertNull($theirs->fresh()->read_at);

        $this->post(route('admin.notifications.read-all'))->assertRedirect()->assertSessionHas('success');
        $this->assertSame(0, AppNotification::query()->where('user_id', $me->id)->unread()->count());
        $this->assertNull($theirs->fresh()->read_at, 'someone else\'s inbox is untouched');
        $this->post(route('admin.notifications.read-all'))->assertSessionHas('success');
    }

    public function test_notification_links_to_the_related_page_when_allowed(): void
    {
        [$me, $emp] = $this->signIn('hr');
        $type = $this->leaveType();
        $request = LeaveRequest::query()->create(['employee_id' => $emp->id, 'leave_type_id' => $type->id, 'start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'days' => 1, 'status' => 'PENDING']);
        $n = $this->note($me, 'Leave request to review', ['type' => 'leave.pending_review', 'entity_type' => $request->getMorphClass(), 'entity_id' => $request->id]);

        $this->post(route('admin.notifications.read', $n))->assertRedirect(route('admin.leave-requests.show', $request));

        // A notification pointing somewhere the user may not go simply goes back.
        $viewer = $this->customUser('bell-only', ['admin.access']);
        $this->actingAs($viewer);
        $m = $this->note($viewer, 'Leave', ['type' => 'leave.pending_review', 'entity_type' => $request->getMorphClass(), 'entity_id' => $request->id]);
        $this->from(route('admin.dashboard'))->post(route('admin.notifications.read', $m))->assertRedirect(route('admin.dashboard'));
        $this->assertNotNull($m->fresh()->read_at);
    }

    public function test_notifications_page_is_private_paginated_and_escaped(): void
    {
        [$me] = $this->signIn('hr');
        [$other] = $this->makeStaff('hr');
        $xss = '<script>alert(1)</script>';
        $this->note($me, $xss, ['body' => $xss]);
        foreach (range(1, 25) as $i) {
            $this->note($me, "Item {$i}");
        }
        $this->note($other, 'Not for me');

        $page = $this->get(route('admin.notifications'))->assertOk()->assertSee('Item 12')->assertDontSee('Not for me');
        $this->assertStringNotContainsString($xss, $page->getContent());
        $this->get(route('admin.notifications', ['page' => 2]))->assertOk()->assertSee('Item 1')->assertDontSee('Item 12');
        $this->assertStringNotContainsString($xss, $this->get(route('admin.dashboard'))->getContent());
    }

    public function test_guests_are_sent_to_login(): void
    {
        auth()->logout();
        $this->get(route('admin.notifications'))->assertRedirect('/login');
        $this->post(route('admin.notifications.read-all'))->assertRedirect('/login');
    }

    public function test_dashboard_payroll_tile_reports_the_latest_period(): void
    {
        $this->actingAs($this->hr)->get(route('admin.dashboard'))->assertSee('Payroll status')->assertSee('None yet');

        $this->paidPerson();
        $period = $this->toReview($this->period());
        $net = number_format((float) $period->run->total_net, 0, '.', ',');
        $this->get(route('admin.dashboard'))->assertOk()->assertSee('Payroll status')->assertSee('Review')->assertSee('July 2026')->assertSee('IDR '.$net)->assertSee(route('admin.payroll.show', $period), false);

        $this->signIn('manager');
        $this->get(route('admin.dashboard'))->assertDontSee('Payroll status')->assertDontSee('Expiring documents');
    }
}
