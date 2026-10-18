<?php

return [
    /*
    | Used only until a company record exists (fresh install). After that the company's own
    | timezone column is the source of truth, editable from Settings.
    */
    'default_timezone' => env('PEOPLEOS_DEFAULT_TIMEZONE', 'Asia/Jakarta'),

    'attendance' => [
        // Private (non-public) disk that stores selfies. Never expose it through the web server.
        'selfie_disk' => env('PEOPLEOS_SELFIE_DISK', 'local'),
        'selfie_max_kb' => 5120,
        // How far into the future a device clock may drift before an offline event is refused.
        'device_clock_skew_seconds' => 300,
        // Corrections can only target work dates this many days back.
        'correction_window_days' => 30,
        'overtime_max_minutes' => 720,
    ],

    'notifications' => [
        // Extra delivery channels (classes implementing App\Services\Notifications\NotificationChannel).
        // In-app notifications are always stored; add push/email drivers here without touching features.
        'channels' => [],
    ],

    // UI languages. Add a code here and a lang/<code> folder (+ Flutter ARB) to support another one.
    'locales' => ['en' => 'English', 'id' => 'Bahasa Indonesia'],

    'api' => [
        'token_expiration_minutes' => (int) env('SANCTUM_TOKEN_EXPIRATION_MINUTES', 43200),
    ],
];
