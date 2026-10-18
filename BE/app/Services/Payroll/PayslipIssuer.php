<?php

namespace App\Services\Payroll;

use App\Models\PayrollItem;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\Payslip;

/**
 * Issues one payslip per payroll item at finalization (inside the finalize transaction, rows locked).
 * Number = {Settings payroll.payslip_prefix}-{period code without dashes}-{sequence, 4 digits},
 * e.g. PS-202607-0001, PS-202607A1-0003. Sequence follows employee number order. Idempotent: items that
 * already have a payslip keep it. Uniqueness is also enforced by the database (payslip_number, payroll_item_id).
 */
class PayslipIssuer
{
    public function __construct(private readonly PayrollSettings $settings) {}

    public function issue(PayrollPeriod $period, PayrollRun $run): int
    {
        $prefix = $this->settings->payslipPrefix();
        $code = strtoupper(str_replace('-', '', $period->code));
        $sequence = (int) Payslip::query()->where('payroll_period_id', $period->id)->max('sequence');
        $issued = 0;

        $items = PayrollItem::query()->where('payroll_run_id', $run->id)
            ->whereDoesntHave('payslip')
            ->orderBy('employee_number')->orderBy('id')
            ->get(['id', 'employee_id']);

        foreach ($items as $item) {
            $sequence++;
            Payslip::query()->create([
                'payroll_item_id' => $item->id,
                'payroll_period_id' => $period->id,
                'employee_id' => $item->employee_id,
                'payslip_number' => sprintf('%s-%s-%04d', $prefix, $code, $sequence),
                'sequence' => $sequence,
                'issued_at' => now(),
            ]);
            $issued++;
        }

        return $issued;
    }
}
