<?php

namespace App\Services\Payroll;

use App\Support\Settings;

/** Typed access to the payroll-related Settings keys (no hardcoded currency). */
class PayrollSettings
{
    public function __construct(private readonly Settings $settings) {}

    public function currency(): string
    {
        return strtoupper(substr(trim($this->settings->string('general.currency')) ?: 'IDR', 0, 3));
    }

    /** Rounding decimals: Settings `payroll.rounding_decimals` (0|1|2), or "auto" = the currency's minor units, capped at 2. */
    public function decimals(?string $currency = null): int
    {
        $configured = trim($this->settings->string('payroll.rounding_decimals'));
        if (in_array($configured, ['0', '1', '2'], true)) {
            return (int) $configured;
        }

        $map = (array) config('payroll.currency_decimals', []);

        return min(2, (int) ($map[$currency ?? $this->currency()] ?? 2));
    }

    public function payslipPrefix(): string
    {
        $prefix = preg_replace('/[^A-Za-z0-9]/', '', $this->settings->string('payroll.payslip_prefix'));

        return $prefix !== '' ? strtoupper($prefix) : 'PS';
    }

    public function requireSeparateApprover(): bool
    {
        return $this->settings->bool('payroll.require_separate_approver');
    }
}
