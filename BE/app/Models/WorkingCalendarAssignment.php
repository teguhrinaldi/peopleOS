<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkingCalendarAssignment extends Model
{
    protected $fillable = ['working_calendar_id', 'employee_id', 'effective_from', 'effective_to'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date'];
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(WorkingCalendar::class, 'working_calendar_id')->withTrashed();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
