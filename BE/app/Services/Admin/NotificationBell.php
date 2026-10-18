<?php

namespace App\Services\Admin;

use App\Models\AppNotification;
use App\Models\AttendanceCorrection;
use App\Models\Document;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\Payslip;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The top-bar bell: the signed-in user's own in-app notifications, never anyone else's. Every query is keyed on the
 * user id, so a notification id of someone else resolves to nothing. Links appear only when the viewer may open the page.
 */
class NotificationBell
{
    private const ICONS = [
        'leave' => 'calendar-days', 'attendance_correction' => 'clipboard-check', 'overtime' => 'timer', 'document' => 'folder', 'payslip' => 'receipt', 'payroll' => 'wallet',
    ];

    public function __construct(private readonly NotificationService $notifications) {}

    /** @return array{unread: int, items: Collection<int, array<string, mixed>>} */
    public function for(User $user, int $limit = 8): array
    {
        $mine = fn () => AppNotification::query()->where('user_id', $user->id);

        return [
            'unread' => $mine()->unread()->count(),
            'items' => $mine()->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get()->map(fn (AppNotification $n) => $this->present($n, $user)),
        ];
    }

    public function paginate(User $user, int $perPage = 20): LengthAwarePaginator
    {
        return AppNotification::query()->where('user_id', $user->id)->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage)->through(fn (AppNotification $n) => $this->present($n, $user));
    }

    /** Mark one of the user's own notifications read. Returns the page it points to, if any. null when it is not theirs. */
    public function read(User $user, int $id): ?AppNotification
    {
        $notification = AppNotification::query()->where('user_id', $user->id)->find($id);
        if ($notification) {
            $this->notifications->markRead($user, $notification);
        }

        return $notification;
    }

    public function readAll(User $user): int
    {
        return $this->notifications->markAllRead($user);
    }

    /** @return array<string, mixed> */
    public function present(AppNotification $n, User $viewer): array
    {
        $group = explode('.', $n->type)[0];

        return [
            'id' => $n->id, 'title' => $n->title, 'body' => $n->body, 'read' => $n->read_at !== null, 'at' => $n->created_at,
            'icon' => self::ICONS[$group] ?? 'bell', 'url' => $this->url($n, $viewer),
        ];
    }

    public function url(AppNotification $n, User $viewer): ?string
    {
        $id = $n->entity_id;
        if (! $id) {
            return null;
        }

        return match ($n->entity_type) {
            (new LeaveRequest)->getMorphClass() => $viewer->hasAnyPermission('leave.view', 'leave.view_team', 'leave.approve', 'leave.approve_team') ? route('admin.leave-requests.show', $id) : null,
            (new AttendanceCorrection)->getMorphClass() => $viewer->hasAnyPermission('attendance.view', 'attendance.view_team') ? route('admin.attendance.corrections') : null,
            (new OvertimeRequest)->getMorphClass() => $viewer->hasAnyPermission('overtime.view', 'overtime.approve', 'overtime.approve_team') ? route('admin.overtime') : null,
            (new Document)->getMorphClass() => $viewer->hasAnyPermission('document.view', 'document.manage') ? route('admin.documents.show', $id) : null,
            (new Payslip)->getMorphClass() => $viewer->hasPermission('payslip.view') ? route('admin.payslips.show', $id) : null,
            default => null,
        };
    }
}
