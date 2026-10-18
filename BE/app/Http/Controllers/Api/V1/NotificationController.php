<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\AppNotification;
use App\Services\Notifications\NotificationService;
use App\Support\Fmt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The caller's own inbox. Every query is keyed on the token's user, so ids of others resolve to 404. */
class NotificationController extends ApiController
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['unread' => ['nullable', 'boolean']]);

        $query = AppNotification::query()->where('user_id', $request->user()->id)->orderByDesc('id');
        if ($request->boolean('unread')) {
            $query->unread();
        }

        $page = $query->paginate($this->perPage($request));
        $unread = AppNotification::query()->where('user_id', $request->user()->id)->unread()->count();

        return $this->ok(
            collect($page->items())->map(fn (AppNotification $n) => $this->present($n))->values(),
            200,
            ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage(), 'unread_count' => $unread],
        );
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return $this->ok(['unread_count' => AppNotification::query()->where('user_id', $request->user()->id)->unread()->count()]);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $notification = AppNotification::query()->where('user_id', $request->user()->id)->findOrFail($id);
        $this->notifications->markRead($request->user(), $notification);

        return $this->ok($this->present($notification->refresh()));
    }

    public function markAllRead(Request $request): JsonResponse
    {
        return $this->ok(['marked' => $this->notifications->markAllRead($request->user())]);
    }

    /** @return array<string, mixed> */
    private function present(AppNotification $n): array
    {
        return [
            'id' => $n->id,
            'type' => $n->type,
            'title' => $n->title,
            'body' => $n->body,
            'entity_type' => $n->entity_type,
            'entity_id' => $n->entity_id,
            'data' => $n->data ?? (object) [],
            'is_read' => $n->read_at !== null,
            'read_at' => Fmt::iso($n->read_at),
            'created_at' => Fmt::iso($n->created_at),
        ];
    }
}
