<?php

namespace App\Services\Documents;

use App\Enums\DocumentStatus;
use App\Enums\EmployeeStatus;
use App\Models\Document;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Collection;

/**
 * Expiry notifications. Recipients: the employee (only when the document is visible to them) and every
 * active user holding document.manage. The dedupe key includes document, state, expiry date and
 * recipient, so a document notifies each person once per state per expiry date no matter how often
 * (or how concurrently) the job runs. Payloads never contain storage paths.
 */
class DocumentNotifier
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function notifyStatus(Document $document, DocumentStatus $status): int
    {
        if (! in_array($status, [DocumentStatus::Expiring, DocumentStatus::Expired], true)) {
            return 0;
        }

        $document->loadMissing(['employee.user', 'type']);
        $employee = $document->employee;

        // Former staff: HR does not need renewal reminders for people who have left.
        if ($employee && ($employee->trashed() || $employee->status === EmployeeStatus::Terminated)) {
            return 0;
        }

        $expiry = $document->expiry_date?->toDateString();
        $expired = $status === DocumentStatus::Expired;
        $type = $expired ? 'document.expired' : 'document.expiring';
        $title = $expired ? 'Document expired' : 'Document expiring';
        $data = ['document_id' => $document->id, 'status' => $status->value, 'expiry_date' => $expiry];
        $sent = 0;

        $recipients = collect();
        if ($employee?->user && $document->visible_to_employee) {
            $recipients->put($employee->user->id, [
                $employee->user,
                $expired
                    ? "Your {$document->title} expired on {$expiry}. Please provide an updated copy."
                    : "Your {$document->title} expires on {$expiry}.",
            ]);
        }

        $subject = $employee ? "{$employee->full_name}: {$document->title}" : "Company document: {$document->title}";
        foreach ($this->managers() as $manager) {
            if (! $recipients->has($manager->id)) {
                $recipients->put($manager->id, [$manager, $expired ? "{$subject} expired on {$expiry}." : "{$subject} expires on {$expiry}."]);
            }
        }

        foreach ($recipients as $userId => [$user, $body]) {
            $this->notifications->notify(
                $user, $type, $title, $body, $document, $data,
                dedupeKey: "document:{$document->id}:{$status->value}:{$expiry}:u{$userId}",
            );
            $sent++;
        }

        return $sent;
    }

    /** @return Collection<int, User> active users whose roles grant document.manage */
    public function managers(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereIn('users.id', function ($q) {
                $q->select('user_roles.user_id')->from('user_roles')
                    ->join('role_permissions', 'role_permissions.role_id', '=', 'user_roles.role_id')
                    ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                    ->where('permissions.slug', 'document.manage');
            })
            ->orderBy('id')
            ->get();
    }
}
