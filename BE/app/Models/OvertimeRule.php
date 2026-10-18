<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Effective-dated overtime pay rule (see docs/overtime.md). Demo values only; no statutory rule is implied. */
class OvertimeRule extends Model
{
    protected $fillable = [
        'company_id', 'name', 'effective_from', 'effective_to', 'employment_type', 'department_id', 'priority',
        'rate_basis', 'rate_divisor', 'multiplier_tiers', 'rounding_minutes', 'rounding_direction',
        'min_minutes_per_request', 'max_minutes_per_day', 'max_minutes_per_period', 'is_active', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'multiplier_tiers' => 'array',
            'rate_divisor' => 'decimal:2',
            'priority' => 'integer',
            'rounding_minutes' => 'integer',
            'min_minutes_per_request' => 'integer',
            'max_minutes_per_day' => 'integer',
            'max_minutes_per_period' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
