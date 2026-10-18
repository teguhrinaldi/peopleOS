<?php

namespace App\Models;

use App\Exceptions\PayrollLockedException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One employee in one run, with a full snapshot (who they were, which inputs were used, every line).
 * Guard: no create/update/delete while the run is FINALIZED or PAID.
 */
class PayrollItem extends Model
{
    protected $fillable = [
        'payroll_run_id', 'employee_id', 'adjusts_payroll_item_id', 'employee_number', 'employee_name',
        'department_name', 'position_name', 'branch_name', 'employment_type', 'join_date', 'terminated_at',
        'salary_reference_date', 'working_days', 'present_days', 'overtime_minutes', 'unpaid_leave_days',
        'basic_salary', 'gross_pay', 'total_deductions', 'net_pay', 'has_negative_net', 'warnings',
    ];

    protected function casts(): array
    {
        return [
            'join_date' => 'date',
            'terminated_at' => 'date',
            'salary_reference_date' => 'date',
            'working_days' => 'integer',
            'present_days' => 'integer',
            'overtime_minutes' => 'integer',
            'unpaid_leave_days' => 'decimal:1',
            'basic_salary' => 'decimal:2',
            'gross_pay' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'net_pay' => 'decimal:2',
            'has_negative_net' => 'boolean',
            'warnings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        $guard = function (PayrollItem $item) {
            if (PayrollRun::statusOf((int) $item->payroll_run_id)?->isLocked()) {
                throw PayrollLockedException::for('payroll item');
            }
        };

        static::saving($guard);
        static::deleting($guard);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollItemLine::class)->orderBy('sort_order')->orderBy('id');
    }

    public function payslip(): HasOne
    {
        return $this->hasOne(Payslip::class);
    }

    public function adjustsItem(): BelongsTo
    {
        return $this->belongsTo(PayrollItem::class, 'adjusts_payroll_item_id');
    }
}
