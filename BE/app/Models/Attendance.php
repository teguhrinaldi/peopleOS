<?php

namespace App\Models;

use App\Enums\AttendanceEventType;
use App\Enums\AttendanceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Attendance extends Model
{
    protected $table = 'attendance';

    protected $fillable = [
        'employee_id', 'attendance_schedule_id', 'work_date', 'status', 'status_before_correction',
        'needs_review', 'check_in_at', 'check_out_at', 'worked_minutes', 'late_minutes',
        'early_leave_minutes', 'is_late', 'is_early_leave', 'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
            'status' => AttendanceStatus::class,
            'status_before_correction' => AttendanceStatus::class,
            'needs_review' => 'boolean',
            'check_in_at' => 'datetime',
            'check_out_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'is_late' => 'boolean',
            'is_early_leave' => 'boolean',
            'worked_minutes' => 'integer',
            'late_minutes' => 'integer',
            'early_leave_minutes' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(AttendanceSchedule::class, 'attendance_schedule_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }

    public function events(): HasMany
    {
        return $this->hasMany(AttendanceEvent::class)->orderBy('occurred_at')->orderBy('id');
    }

    public function eventOfType(AttendanceEventType $type): ?AttendanceEvent
    {
        $events = $this->relationLoaded('events') ? $this->events : $this->events()->get();

        return $events->firstWhere('type', $type);
    }

    public function isOpen(): bool
    {
        return $this->check_in_at !== null && $this->check_out_at === null
            && in_array($this->status, [AttendanceStatus::CheckedIn, AttendanceStatus::Pending], true);
    }
}
