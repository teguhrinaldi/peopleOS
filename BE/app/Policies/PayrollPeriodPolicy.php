<?php

namespace App\Policies;

use App\Models\PayrollPeriod;
use App\Models\User;
use App\Support\CompanyContext;

/** Payroll is company-wide data: permission + same company. Workflow permissions are re-checked in the services. */
class PayrollPeriodPolicy
{
    public function __construct(private readonly CompanyContext $context) {}

    public function view(User $user, PayrollPeriod $period): bool
    {
        return $user->hasPermission('payroll.view') && $period->company_id === $this->context->id();
    }

    public function manage(User $user, PayrollPeriod $period): bool
    {
        return $user->hasPermission('payroll.manage') && $period->company_id === $this->context->id();
    }
}
