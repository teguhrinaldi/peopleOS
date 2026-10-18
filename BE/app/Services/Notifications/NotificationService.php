<?php

namespace App\Services\Notifications;

use App\Models\AppNotification;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The only way features notify people. Feature code says *what* happened; this class decides
 * *how* it is delivered: always an in-app row, then any configured extra channels.
 *
 * Delivery runs after the surrounding transaction commits, so a rolled-back approval never notifies.
 */
class NotificationService
{
    /**
     * @param  array<string, mixed>  $data  small, non-sensitive payload the client may use for navigation
     */
    public function notify(
        User|Employee|null $recipient,
        string $type,
        string $title,
        ?string $body = null,
        ?Model $entity = null,
        array $data = [],
        ?string $dedupeKey = null,
    ): void {
        $user = $recipient instanceof Employee ? $recipient->user : $recipient;
        if (! $user || ! $user->is_active) {
            return; // employees without a login (or disabled logins) have no inbox
        }

        DB::afterCommit(function () use ($user, $type, $title, $body, $entity, $data, $dedupeKey) {
            try {
                $notification = AppNotification::query()->create([
                    'user_id' => $user->id,
                    'type' => $type,
                    'title' => $title,
                    'body' => $body,
                    'entity_type' => $entity?->getMorphClass(),
                    'entity_id' => $entity?->getKey(),
                    'data' => $data ?: null,
                    'dedupe_key' => $dedupeKey,
                ]);
            } catch (UniqueConstraintViolationException) {
                return; // already notified for this event
            }

            foreach ($this->channels() as $channel) {
                try {
                    $channel->send($notification);
                } catch (Throwable $e) {
                    // A broken push/email provider must never break the business action that triggered it.
                    Log::warning('Notification channel failed', ['channel' => $channel::class, 'error' => $e->getMessage()]);
                }
            }
        });
    }

    public function markRead(User $user, AppNotification $notification): void
    {
        if ($notification->user_id === $user->id && $notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }
    }

    public function markAllRead(User $user): int
    {
        return AppNotification::query()->where('user_id', $user->id)->unread()->update(['read_at' => now()]);
    }

    /** @return list<NotificationChannel> */
    private function channels(): array
    {
        return array_map(fn (string $class) => app($class), config('peopleos.notifications.channels', []));
    }
}
