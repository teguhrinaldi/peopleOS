<?php

namespace App\Models;

use App\Enums\DayType;
use App\Enums\GeofenceMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AttendanceSchedule extends Model
{
    protected $fillable = [
        'employee_id', 'work_date', 'shift_id', 'work_location_id', 'attendance_policy_id', 'holiday_id',
        'is_working_day', 'day_type', 'timezone', 'shift_name', 'expected_start_at', 'expected_end_at',
        'grace_late_minutes', 'grace_early_leave_minutes', 'break_minutes', 'expected_working_minutes',
        'location_name', 'location_latitude', 'location_longitude', 'location_radius_meters',
        'require_gps', 'geofence_mode', 'require_selfie', 'max_gps_accuracy_meters',
        'reject_mock_location', 'offline_max_age_minutes',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
            'is_working_day' => 'boolean',
            'day_type' => DayType::class,
            'expected_start_at' => 'datetime',
            'expected_end_at' => 'datetime',
            'location_latitude' => 'float',
            'location_longitude' => 'float',
            'location_radius_meters' => 'integer',
            'require_gps' => 'boolean',
            'require_selfie' => 'boolean',
            'reject_mock_location' => 'boolean',
            'geofence_mode' => GeofenceMode::class,
            'grace_late_minutes' => 'integer',
            'grace_early_leave_minutes' => 'integer',
            'break_minutes' => 'integer',
            'expected_working_minutes' => 'integer',
            'max_gps_accuracy_meters' => 'integer',
            'offline_max_age_minutes' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class)->withTrashed();
    }

    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class)->withTrashed();
    }

    public function attendance(): HasOne
    {
        return $this->hasOne(Attendance::class);
    }

    public function hasLocation(): bool
    {
        return $this->location_latitude !== null && $this->location_longitude !== null;
    }
}
