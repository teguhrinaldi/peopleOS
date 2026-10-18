<?php

namespace App\Models;

use App\Enums\PayrollStatus;
use App\Enums\SalaryComponentKind;
use App\Exceptions\PayrollLockedException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/** A snapshotted earning or deduction line. `amount` is never negative; `kind` gives its sign. */
class PayrollItemLine extends Model
{
    protected $fillable = [
        'payroll_item_id', 'salary_component_id', 'payroll_adjustment_id', 'component_code', 'component_name',
        'kind', 'calculation', 'rate', 'quantity', 'unit_amount', 'base_amount', 'amount', 'description',
        'adjustment_reason', 'sort_order', 'source_key', 'source_refs', 'minutes', 'multiplier',
    ];

    protected function casts(): array
    {
        return [
            'kind' => SalaryComponentKind::class,
            'rate' => 'decimal:4',
            'quantity' => 'decimal:2',
            'unit_amount' => 'decimal:2',
            'base_amount' => 'decimal:2',
            'amount' => 'decimal:2',
            'sort_order' => 'integer',
            'source_refs' => 'array',
            'minutes' => 'integer',
            'multiplier' => 'decimal:3',
        ];
    }

    protected static function booted(): void
    {
        $guard = function (PayrollItemLine $line) {
            $status = DB::table('payroll_items')
                ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_items.payroll_run_id')
                ->where('payroll_items.id', $line->payroll_item_id)
                ->value('payroll_runs.status');

            if (PayrollStatus::tryFrom((string) $status)?->isLocked()) {
                throw PayrollLockedException::for('payroll line');
            }
        };

        static::saving($guard);
        static::deleting($guard);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(PayrollItem::class, 'payroll_item_id');
    }
}
