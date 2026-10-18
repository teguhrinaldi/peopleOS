<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * available = allowance + carried_over + adjustment - used - reserved   (NULL allowance = unlimited)
 * Decimal columns are strings; all arithmetic uses bcmath with one decimal place.
 */
class LeaveBalance extends Model
{
    protected $fillable = ['employee_id', 'leave_type_id', 'year', 'allowance', 'carried_over', 'adjustment', 'used', 'reserved'];

    protected function casts(): array
    {
        return ['year' => 'integer'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class)->withTrashed();
    }

    public function isUnlimited(): bool
    {
        return $this->allowance === null;
    }

    public function entitlement(): string
    {
        return bcadd(bcadd((string) ($this->allowance ?? 0), (string) $this->carried_over, 1), (string) $this->adjustment, 1);
    }

    /** NULL when unlimited. */
    public function available(): ?string
    {
        if ($this->isUnlimited()) {
            return null;
        }

        return bcsub(bcsub($this->entitlement(), (string) $this->used, 1), (string) $this->reserved, 1);
    }
}
