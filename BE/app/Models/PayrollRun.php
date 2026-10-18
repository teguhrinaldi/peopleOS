<?php

namespace App\Models;

use App\Enums\PayrollStatus;
use App\Exceptions\PayrollLockedException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * The single payroll run of a period. Totals are computed from its stored items (SUM) when they are written.
 *
 * Model guard: once FINALIZED the only permitted write is FINALIZED -> PAID (with the payment fields);
 * a PAID run cannot be written at all. Runs are never deleted.
 */
class PayrollRun extends Model
{
    private const PAYMENT_FIELDS = ['status', 'paid_by', 'paid_at', 'payment_reference', 'updated_at'];

    protected $fillable = [
        'payroll_period_id', 'status', 'currency', 'decimals', 'company_snapshot', 'summary', 'employee_count',
        'total_gross', 'total_deductions', 'total_net', 'negative_net_count',
        'processing_token', 'processing_from', 'processing_started_at', 'processing_by',
        'generated_by', 'generated_at', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at',
        'finalized_by', 'finalized_at', 'paid_by', 'paid_at', 'payment_reference',
        'cancelled_by', 'cancelled_at', 'cancel_reason', 'return_reason',
    ];

    protected $hidden = ['processing_token'];

    protected function casts(): array
    {
        return [
            'status' => PayrollStatus::class,
            'company_snapshot' => 'array',
            'summary' => 'array',
            'decimals' => 'integer',
            'employee_count' => 'integer',
            'negative_net_count' => 'integer',
            'total_gross' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'total_net' => 'decimal:2',
            'processing_started_at' => 'datetime',
            'generated_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'finalized_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (PayrollRun $run) {
            $from = PayrollStatus::tryFrom((string) $run->getRawOriginal('status'));
            if (! $from?->isLocked()) {
                return;
            }
            $becomesPaid = $from === PayrollStatus::Finalized && $run->status === PayrollStatus::Paid
                && array_diff(array_keys($run->getDirty()), self::PAYMENT_FIELDS) === [];
            if (! $becomesPaid) {
                throw PayrollLockedException::for('payroll run');
            }
        });

        static::deleting(fn () => throw PayrollLockedException::for('payroll run'));
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayrollItem::class)->orderBy('employee_number')->orderBy('id');
    }

    public function isLocked(): bool
    {
        return $this->status->isLocked();
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by')->withTrashed();
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by')->withTrashed();
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by')->withTrashed();
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by')->withTrashed();
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by')->withTrashed();
    }

    public static function statusOf(int $runId): ?PayrollStatus
    {
        return PayrollStatus::tryFrom((string) DB::table('payroll_runs')->where('id', $runId)->value('status'));
    }
}
