<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftBreak extends Model
{
    protected $fillable = ['shift_id', 'name', 'start_time', 'end_time', 'duration_minutes', 'is_paid'];

    protected function casts(): array
    {
        return ['is_paid' => 'boolean', 'duration_minutes' => 'integer'];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
