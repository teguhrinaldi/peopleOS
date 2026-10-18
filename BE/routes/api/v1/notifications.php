<?php

use App\Http\Controllers\Api\V1\NotificationController;
use Illuminate\Support\Facades\Route;

// Included inside the authenticated /api/v1 group in routes/api.php.
Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread');
Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
Route::post('notifications/{id}/read', [NotificationController::class, 'markRead'])->whereNumber('id')->name('notifications.read');
