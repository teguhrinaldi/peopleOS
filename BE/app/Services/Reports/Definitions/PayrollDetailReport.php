<?php

namespace App\Services\Reports\Definitions;

use App\Models\User;
use App\Services\Reports\Report;
use App\Services\Reports\ReportFilters;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Per-employee gross, deductions and net for one payroll period (period_id), from the stored items. */
class PayrollDetailReport extends Report
{
    public function key(): string
    {
        return 'payroll.detail';
    }

    public function title(): string
    {
        return 'Payroll by employee';
    }

    public function group(): string
    {
        return 'payroll';
    }

    public function description(): string
    {
        return 'Every employee in one payroll period: gross, deductions and net.';
    }

    public function permissions(): array
    {
        return ['payroll.view'];
    }

    public function filters(): array
    {
        return ['period_id', 'department_id'];
    }

    public function columns(): array
    {
        return ['employee_number' => 'Employee no.', 'employee' => 'Employee', 'department' => 'Department', 'position' => 'Position', 'present_days' => 'Present days',
            'gross' => 'Gross', 'deductions' => 'Deductions', 'net' => 'Net'];
    }

    public function numericColumns(): array
    {
        return ['present_days', 'gross', 'deductions', 'net'];
    }

    public function rows(ReportFilters $f, User $viewer): Collection
    {
        if (! $f->periodId) {
            return collect();
        }

        $run = DB::table('payroll_runs as r')->join('payroll_periods as p', 'p.id', '=', 'r.payroll_period_id')
            ->where('p.company_id', $this->ctx->company->id())->where('p.id', $f->periodId)->first(['r.id', 'r.decimals']);
        if (! $run) {
            return collect();
        }

        $q = DB::table('payroll_items')->where('payroll_run_id', $run->id);
        if ($f->departmentId) {
            $name = DB::table('departments')->where('id', $f->departmentId)->value('name');
            $q->where('department_name', $name);
        }

        return $q->orderBy('employee_name')->get(['employee_number', 'employee_name', 'department_name', 'position_name', 'present_days', 'gross_pay', 'total_deductions', 'net_pay'])
            ->map(fn ($r) => [
                'employee_number' => $r->employee_number, 'employee' => $r->employee_name, 'department' => $r->department_name, 'position' => $r->position_name,
                'present_days' => (int) $r->present_days, 'gross' => $this->ctx->money($r->gross_pay, (int) $run->decimals),
                'deductions' => $this->ctx->money($r->total_deductions, (int) $run->decimals), 'net' => $this->ctx->money($r->net_pay, (int) $run->decimals),
            ]);
    }

    public function totals(Collection $rows): ?array
    {
        $sum = fn (string $k) => number_format($rows->sum(fn ($r) => (float) str_replace(',', '', $r[$k])), 0, '.', ',');

        return ['employee' => $rows->count().' employees', 'gross' => $sum('gross'), 'deductions' => $sum('deductions'), 'net' => $sum('net')];
    }
}
