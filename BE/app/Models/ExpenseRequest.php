<?php

namespace App\Models;

use App\Enums\ExpenseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Reimbursement claim. No soft delete and no delete path at all: history is kept, requests are cancelled instead.
 * `receipt_disk` / `receipt_path` are hidden from every serialization and must never reach JSON or logs.
 */
class ExpenseRequest extends Model
{
    protected $fillable = [
        'company_id', 'request_number', 'employee_id', 'expense_category_id', 'expense_date', 'currency',
        'submitted_amount', 'approved_amount', 'business_purpose', 'description', 'status', 'payment_route', 'policy_snapshot',
        'receipt_disk', 'receipt_path', 'receipt_original_name', 'receipt_mime', 'receipt_extension', 'receipt_size_bytes', 'receipt_sha256',
        'requested_by', 'submitted_at', 'review_started_by', 'review_started_at', 'reviewed_by', 'reviewed_at', 'review_note',
        'rejection_reason', 'adjustment_reason', 'cancelled_by', 'cancelled_at', 'cancel_reason',
        'scheduled_by', 'scheduled_at', 'scheduled_for', 'paid_at', 'paid_by',
        'payroll_item_line_id', 'payroll_period_id', 'payroll_included_at',
    ];

    protected $hidden = ['receipt_disk', 'receipt_path'];

    protected function casts(): array
    {
        return [
            'status' => ExpenseStatus::class,
            'expense_date' => 'date:Y-m-d',
            'policy_snapshot' => 'array',
            'receipt_size_bytes' => 'integer',
            'submitted_at' => 'datetime',
            'review_started_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'scheduled_for' => 'date:Y-m-d',
            'paid_at' => 'datetime',
            'payroll_included_at' => 'datetime',
        ];
    }

    public function hasReceipt(): bool
    {
        return $this->receipt_path !== null;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }

    public function events(): HasMany
    {
        return $this->hasMany(ExpenseRequestEvent::class)->orderBy('id');
    }

    public function payment(): HasOne
    {
        return $this->hasOne(ExpensePayment::class);
    }
}
