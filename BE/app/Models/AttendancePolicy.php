<?php

namespace App\Models;

use App\Enums\GeofenceMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AttendancePolicy extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'name', 'is_default', 'require_gps', 'geofence_mode', 'require_selfie',
        'max_gps_accuracy_meters', 'reject_mock_location', 'offline_max_age_minutes',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'require_gps' => 'boolean',
            'require_selfie' => 'boolean',
            'reject_mock_location' => 'boolean',
            'geofence_mode' => GeofenceMode::class,
            'max_gps_accuracy_meters' => 'integer',
            'offline_max_age_minutes' => 'integer',
        ];
    }

    public function locations(): HasMany
    {
        return $this->hasMany(WorkLocation::class);
    }
}
