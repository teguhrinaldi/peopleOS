<?php

namespace App\Services\Notifications;

use App\Models\AppNotification;

/**
 * Extra delivery channel (push, email, chat...). The in-app record is always written first;
 * channels receive the stored notification. Register implementations in config/peopleos.php.
 */
interface NotificationChannel
{
    public function send(AppNotification $notification): void;
}
