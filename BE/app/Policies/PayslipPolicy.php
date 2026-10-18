<?php

namespace App\Policies;

use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\User;
use App\Services\AccessScope;

/**
 * payslip.view      -> every payslip of the company
 * payslip.view_own  -> only the caller's own payslips, and only once the payroll is FINALIZED or PAID
 */
class PayslipPolicy
{
    public function __construct(private readonly AccessScope $scope) {}

    public function view(User $user, Payslip $payslip): bool
    {
        if ($user->hasPermission('payslip.view') && $this->scope->canAccess($user, $payslip->employee_id, 'payslip.view', null, false)) {
            return true;
        }

        return $user->hasPermission('payslip.view_own')
            && $this->scope->isSelf($user, $payslip->employee_id)
            && $this->released($payslip);
    }

    private function released(Payslip $payslip): bool
    {
        $runId = $payslip->item()->value('payroll_run_id');

        return (bool) PayrollRun::statusOf((int) $runId)?->isReleased();
    }
}
