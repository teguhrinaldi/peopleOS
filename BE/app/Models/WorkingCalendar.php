<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkingCalendar extends Model
{
    use SoftDeletes;

    protected $fillable = ['company_id', 'name', 'description', 'is_default'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function days(): HasMany
    {
        return $this->hasMany(WorkingCalendarDay::class);
    }

    public function isWorkingDay(int $isoDayOfWeek): bool
    {
        $day = $this->days->firstWhere('day_of_week', $isoDayOfWeek);

        return $day ? (bool) $day->is_working_day : false;
    }
}
