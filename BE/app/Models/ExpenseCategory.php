<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpenseCategory extends Model
{
    protected $fillable = [
        'company_id', 'code', 'name', 'description', 'is_active', 'receipt_required',
        'max_amount_per_claim', 'max_amount_per_month', 'max_age_days', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'receipt_required' => 'boolean', 'max_age_days' => 'integer', 'sort_order' => 'integer'];
    }
}
