<?php

namespace App\Models;

use App\Enums\AttendanceEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'attendance_id', 'employee_id', 'type', 'idempotency_key', 'occurred_at', 'received_at',
        'device_captured_at', 'time_source', 'is_offline_sync', 'latitude', 'longitude', 'accuracy_meters',
        'location_captured_at', 'is_mock_location', 'distance_meters', 'inside_geofence', 'selfie_path',
        'selfie_meta', 'device_info',
    ];

    /** Selfie paths and raw device data never leave the API in bulk. */
    protected $hidden = ['selfie_path', 'selfie_meta', 'device_info'];

    protected function casts(): array
    {
        return [
            'type' => AttendanceEventType::class,
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
            'device_captured_at' => 'datetime',
            'location_captured_at' => 'datetime',
            'is_offline_sync' => 'boolean',
            'is_mock_location' => 'boolean',
            'inside_geofence' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
            'accuracy_meters' => 'float',
            'distance_meters' => 'float',
            'selfie_meta' => 'array',
            'device_info' => 'array',
        ];
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
