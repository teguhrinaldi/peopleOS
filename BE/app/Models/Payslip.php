<?php

namespace App\Models;

use App\Exceptions\PayrollLockedException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Issued at finalization, one per payroll item. The PDF lives on the private disk; `file_path` is hidden
 * and never serialised. Guard: the slip is never deleted, and after issue only document bookkeeping may change
 * (the PDF file fields while no file is recorded - or while PayslipDocuments repairs a missing file - and notified_at).
 */
class Payslip extends Model
{
    private const FILE_FIELDS = ['file_path', 'file_size', 'file_sha256', 'file_generated_at'];

    /** Set by PayslipDocuments only after it verified that the recorded file is missing on disk. */
    public bool $repairingMissingFile = false;

    protected $fillable = [
        'payroll_item_id', 'payroll_period_id', 'employee_id', 'payslip_number', 'sequence', 'issued_at',
        'file_path', 'file_size', 'file_sha256', 'file_generated_at', 'notified_at',
    ];

    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'file_generated_at' => 'datetime',
            'notified_at' => 'datetime',
            'sequence' => 'integer',
            'file_size' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Payslip $slip) {
            $dirty = array_diff(array_keys($slip->getDirty()), ['updated_at']);
            $fileChange = array_intersect($dirty, self::FILE_FIELDS) !== [];
            $other = array_diff($dirty, [...self::FILE_FIELDS, 'notified_at']);

            $fileAllowed = $slip->getRawOriginal('file_path') === null || $slip->repairingMissingFile;
            if ($other !== [] || ($fileChange && ! $fileAllowed)) {
                throw PayrollLockedException::for('payslip');
            }
        });

        static::deleting(fn () => throw PayrollLockedException::for('payslip'));
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(PayrollItem::class, 'payroll_item_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function hasFile(): bool
    {
        return $this->file_path !== null;
    }
}
