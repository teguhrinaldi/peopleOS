<?php

namespace App\Models;

use App\Enums\RequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * requested_minutes (employee) -> approved_minutes (reviewer) -> verified_minutes (attendance cross-check).
 * payroll_status: NONE | PENDING_PAYROLL | INCLUDED | ADJUSTED | LEGACY (see docs/overtime.md).
 */
class OvertimeRequest extends Model
{
    public const PAYROLL_NONE = 'NONE';

    public const PAYROLL_PENDING = 'PENDING_PAYROLL';

    public const PAYROLL_INCLUDED = 'INCLUDED';

    public const PAYROLL_ADJUSTED = 'ADJUSTED';

    public const PAYROLL_LEGACY = 'LEGACY';

    protected $fillable = [
        'employee_id', 'work_date', 'start_at', 'end_at', 'requested_minutes', 'approved_minutes', 'reason',
        'status', 'requested_by', 'reviewed_by', 'reviewed_at', 'review_note',
        'verified_minutes', 'verification_status', 'verified_at', 'day_type', 'payroll_status',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
            'status' => RequestStatus::class,
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'verified_at' => 'datetime',
            'requested_minutes' => 'integer',
            'approved_minutes' => 'integer',
            'verified_minutes' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** The live (not voided) payroll inclusion, if any. */
    public function inclusion(): HasOne
    {
        return $this->hasOne(OvertimePayrollInclusion::class)->whereNotNull('active_request_id');
    }
}
