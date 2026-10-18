<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpensePolicy extends Model
{
    public const APPROVAL_ROUTES = ['MANAGER_OR_HR', 'HR_ONLY'];

    public const PAYMENT_ROUTES = ['SEPARATE', 'PAYROLL'];

    protected $fillable = ['company_id', 'approval_route', 'payment_route', 'max_amount_per_claim', 'max_amount_per_month', 'max_receipt_kb', 'updated_by'];

    protected function casts(): array
    {
        return ['max_receipt_kb' => 'integer'];
    }
}
