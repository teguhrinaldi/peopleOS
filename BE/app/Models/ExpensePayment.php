<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only payment record. expense_request_id is UNIQUE: a request can be paid exactly once. */
class ExpensePayment extends Model
{
    public const UPDATED_AT = null;

    public const METHODS = ['BANK_TRANSFER', 'CASH', 'E_WALLET', 'OTHER', 'PAYROLL'];

    protected $fillable = ['expense_request_id', 'idempotency_key', 'amount', 'currency', 'method', 'reference', 'paid_at', 'paid_by', 'note'];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Expense payments are append-only.'));
        static::deleting(fn () => throw new LogicException('Expense payments are append-only.'));
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by')->withTrashed();
    }
}
