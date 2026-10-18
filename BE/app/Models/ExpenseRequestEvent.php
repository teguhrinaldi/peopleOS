<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only lifecycle history (also enforced by database triggers). */
class ExpenseRequestEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['expense_request_id', 'action', 'from_status', 'to_status', 'actor_id', 'note', 'data'];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Expense history is append-only.'));
        static::deleting(fn () => throw new LogicException('Expense history is append-only.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id')->withTrashed();
    }
}
