<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkingCalendarDay extends Model
{
    public $timestamps = false;

    protected $fillable = ['working_calendar_id', 'day_of_week', 'is_working_day'];

    protected function casts(): array
    {
        return ['day_of_week' => 'integer', 'is_working_day' => 'boolean'];
    }
}
