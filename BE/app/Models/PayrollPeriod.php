<?php

namespace App\Models;

use App\Enums\PayrollPeriodType;
use App\Enums\PayrollStatus;
use App\Exceptions\PayrollLockedException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A pay period (REGULAR) or a correction of a finalized one (ADJUSTMENT, linked by adjusts_payroll_period_id).
 * `status` mirrors its run's status and is only written by PayrollWorkflow.
 */
class PayrollPeriod extends Model
{
    protected $fillable = [
        'company_id', 'type', 'code', 'name', 'start_date', 'end_date', 'pay_date', 'adjusts_payroll_period_id',
        'adjustment_reason', 'status', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => PayrollPeriodType::class,
            'status' => PayrollStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'pay_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (PayrollPeriod $period) {
            $from = PayrollStatus::tryFrom((string) $period->getRawOriginal('status'));
            if (! $from?->isLocked()) {
                return;
            }
            $dirty = array_diff(array_keys($period->getDirty()), ['status', 'updated_at']);
            $becomesPaid = $from === PayrollStatus::Finalized && $period->status === PayrollStatus::Paid;
            if ($dirty !== [] || ! $becomesPaid) {
                throw PayrollLockedException::for('payroll period');
            }
        });

        // Periods are cancelled, never deleted.
        static::deleting(fn () => throw PayrollLockedException::for('payroll period'));
    }

    public function run(): HasOne
    {
        return $this->hasOne(PayrollRun::class);
    }

    public function original(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'adjusts_payroll_period_id');
    }

    public function adjustmentPeriods(): HasMany
    {
        return $this->hasMany(PayrollPeriod::class, 'adjusts_payroll_period_id')->orderBy('id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(PayrollAdjustment::class)->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function isAdjustment(): bool
    {
        return $this->type === PayrollPeriodType::Adjustment;
    }
}
