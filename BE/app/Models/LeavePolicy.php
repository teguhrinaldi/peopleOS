<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeavePolicy extends Model
{
    protected $fillable = [
        'leave_type_id', 'employment_type', 'annual_allowance', 'prorate_first_year', 'carry_over_max', 'allow_negative',
        'max_negative_days', 'allow_half_day', 'allow_overlap', 'min_notice_days', 'max_past_days', 'max_consecutive_days',
        'requires_reason',
    ];

    protected function casts(): array
    {
        return [
            'prorate_first_year' => 'boolean',
            'allow_negative' => 'boolean',
            'allow_half_day' => 'boolean',
            'allow_overlap' => 'boolean',
            'requires_reason' => 'boolean',
            'min_notice_days' => 'integer',
            'max_past_days' => 'integer',
            'max_consecutive_days' => 'integer',
        ];
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class)->withTrashed();
    }

    public function isUnlimited(): bool
    {
        return $this->annual_allowance === null;
    }

    /** @return array<string, mixed> frozen onto each request so later policy edits never reinterpret it */
    public function snapshot(): array
    {
        return $this->only($this->fillable) + ['id' => $this->id];
    }
}
