<?php

namespace App\Models;

use App\Exceptions\PayrollLockedException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "This overtime request was paid by this payroll line." Live while active_request_id is set (UNIQUE).
 * Guard: once locked (run finalized) the row can only be voided (void columns + active_request_id), never
 * edited or deleted.
 */
class OvertimePayrollInclusion extends Model
{
    private const VOID_FIELDS = ['active_request_id', 'voided_at', 'voided_by', 'void_reason', 'updated_at'];

    protected $fillable = [
        'overtime_request_id', 'active_request_id', 'employee_id', 'payroll_run_id', 'payroll_item_line_id',
        'overtime_rule_id', 'minutes', 'day_type', 'multiplier', 'rate', 'amount', 'segments', 'snapshot',
        'locked_at', 'voided_at', 'voided_by', 'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'minutes' => 'integer',
            'multiplier' => 'decimal:3',
            'rate' => 'decimal:2',
            'amount' => 'decimal:2',
            'segments' => 'array',
            'snapshot' => 'array',
            'locked_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (OvertimePayrollInclusion $row) {
            if ($row->getRawOriginal('locked_at') !== null && array_diff(array_keys($row->getDirty()), self::VOID_FIELDS) !== []) {
                throw PayrollLockedException::for('overtime inclusion');
            }
        });
        static::deleting(function (OvertimePayrollInclusion $row) {
            if ($row->getRawOriginal('locked_at') !== null) {
                throw PayrollLockedException::for('overtime inclusion');
            }
        });
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(OvertimeRequest::class, 'overtime_request_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(PayrollItemLine::class, 'payroll_item_line_id');
    }
}
