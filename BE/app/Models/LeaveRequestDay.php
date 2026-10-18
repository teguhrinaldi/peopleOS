<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequestDay extends Model
{
    public $timestamps = false;

    protected $fillable = ['leave_request_id', 'employee_id', 'date', 'slot', 'blocking'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'blocking' => 'boolean'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'leave_request_id');
    }
}
