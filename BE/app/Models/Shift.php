<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Shift extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'code', 'name', 'start_time', 'end_time', 'crosses_midnight', 'grace_late_minutes',
        'grace_early_leave_minutes', 'break_minutes', 'working_minutes', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'crosses_midnight' => 'boolean',
            'is_active' => 'boolean',
            'grace_late_minutes' => 'integer',
            'grace_early_leave_minutes' => 'integer',
            'break_minutes' => 'integer',
            'working_minutes' => 'integer',
        ];
    }

    public function breaks(): HasMany
    {
        return $this->hasMany(ShiftBreak::class);
    }

    /** "HH:MM" without seconds, for display. */
    public function startLabel(): string
    {
        return substr((string) $this->start_time, 0, 5);
    }

    public function endLabel(): string
    {
        return substr((string) $this->end_time, 0, 5);
    }
}
