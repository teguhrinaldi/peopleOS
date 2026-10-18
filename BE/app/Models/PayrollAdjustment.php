<?php

namespace App\Models;

use App\Enums\PayrollStatus;
use App\Enums\SalaryComponentKind;
use App\Exceptions\PayrollLockedException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/** A manual correction entry of an ADJUSTMENT period. Editable only while that period is DRAFT. */
class PayrollAdjustment extends Model
{
    protected $fillable = ['payroll_period_id', 'employee_id', 'kind', 'label', 'amount', 'reason', 'created_by'];

    protected function casts(): array
    {
        return ['kind' => SalaryComponentKind::class, 'amount' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        $guard = function (PayrollAdjustment $entry) {
            $status = PayrollStatus::tryFrom((string) DB::table('payroll_periods')->where('id', $entry->payroll_period_id)->value('status'));
            if ($status !== PayrollStatus::Draft) {
                throw PayrollLockedException::for('adjustment entry');
            }
        };

        static::saving($guard);
        static::deleting($guard);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }
}
