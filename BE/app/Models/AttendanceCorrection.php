<?php

namespace App\Models;

use App\Enums\RequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceCorrection extends Model
{
    protected $fillable = [
        'employee_id', 'attendance_id', 'attendance_schedule_id', 'work_date', 'requested_check_in_at',
        'requested_check_out_at', 'reason', 'status', 'requested_by', 'reviewed_by', 'reviewed_at',
        'review_note', 'original_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
            'status' => RequestStatus::class,
            'requested_check_in_at' => 'datetime',
            'requested_check_out_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'original_snapshot' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(AttendanceSchedule::class, 'attendance_schedule_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
