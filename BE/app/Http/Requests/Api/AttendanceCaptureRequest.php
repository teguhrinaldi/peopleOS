<?php

namespace App\Http\Requests\Api;

use App\Services\Attendance\AttendanceCapture;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/** Shared by check-in and check-out. Accepts JSON or multipart (when a selfie is attached). */
class AttendanceCaptureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('attendance.self');
    }

    /** Multipart sends "true"/"false" strings, JSON sends real booleans; accept both. */
    protected function prepareForValidation(): void
    {
        foreach (['is_offline_sync', 'is_mock_location'] as $flag) {
            if ($this->has($flag)) {
                $this->merge([$flag => filter_var($this->input($flag), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100', 'regex:/^[A-Za-z0-9._:\-]+$/'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'accuracy_meters' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'location_captured_at' => ['nullable', 'date'],
            'device_captured_at' => ['nullable', 'date'],
            'is_offline_sync' => ['nullable', 'boolean'],
            'is_mock_location' => ['nullable', 'boolean'],
            'device_platform' => ['nullable', 'string', 'max:30'],
            'device_model' => ['nullable', 'string', 'max:80'],
            'app_version' => ['nullable', 'string', 'max:30'],
            'selfie' => [
                'nullable', 'file', 'mimes:jpg,jpeg,png', 'mimetypes:image/jpeg,image/png',
                'max:'.config('peopleos.attendance.selfie_max_kb', 5120),
            ],
        ];
    }

    public function toCapture(): AttendanceCapture
    {
        $parse = fn (?string $value) => $value ? CarbonImmutable::parse($value)->utc() : null;

        return new AttendanceCapture(
            idempotencyKey: $this->string('idempotency_key')->toString(),
            latitude: $this->filled('latitude') ? (float) $this->input('latitude') : null,
            longitude: $this->filled('longitude') ? (float) $this->input('longitude') : null,
            accuracyMeters: $this->filled('accuracy_meters') ? (float) $this->input('accuracy_meters') : null,
            locationCapturedAt: $parse($this->input('location_captured_at')),
            deviceCapturedAt: $parse($this->input('device_captured_at')),
            isOfflineSync: $this->boolean('is_offline_sync'),
            isMockLocation: $this->boolean('is_mock_location'),
            deviceInfo: array_filter([
                'platform' => $this->input('device_platform'),
                'model' => $this->input('device_model'),
                'app_version' => $this->input('app_version'),
                'user_agent' => $this->userAgent(),
            ]),
        );
    }
}
