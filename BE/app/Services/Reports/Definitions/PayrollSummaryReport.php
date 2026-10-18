<?php

namespace App\Services\Reports\Definitions;

use App\Models\User;
use App\Services\Reports\Report;
use App\Services\Reports\ReportFilters;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** One row per payroll period from the stored run totals (the snapshot, never recomputed from today's salaries). */
class PayrollSummaryReport extends Report
{
    public function key(): string
    {
        return 'payroll.summary';
    }

    public function title(): string
    {
        return 'Payroll summary';
    }

    public function group(): string
    {
        return 'payroll';
    }

    public function description(): string
    {
        return 'Gross, deductions and net per payroll period, as stored when the payroll was generated.';
    }

    public function permissions(): array
    {
        return ['payroll.view'];
    }

    public function filters(): array
    {
        return ['from', 'to', 'status'];
    }

    public function columns(): array
    {
        return ['period' => 'Period', 'type' => 'Type', 'pay_date' => 'Pay date', 'status' => 'Status', 'employees' => 'Employees', 'currency' => 'Currency',
            'gross' => 'Gross', 'deductions' => 'Deductions', 'net' => 'Net'];
    }

    public function numericColumns(): array
    {
        return ['employees', 'gross', 'deductions', 'net'];
    }

    public function rows(ReportFilters $f, User $viewer): Collection
    {
        $q = DB::table('payroll_periods as p')->join('payroll_runs as r', 'r.payroll_period_id', '=', 'p.id')
            ->where('p.company_id', $this->ctx->company->id())
            ->where('p.end_date', '>=', $f->from)->where('p.start_date', '<=', $f->to);
        if ($f->status) {
            $q->where('r.status', $f->status);
        }

        return $q->orderByDesc('p.start_date')->get(['p.name', 'p.type', 'p.pay_date', 'r.status', 'r.employee_count', 'r.currency', 'r.decimals', 'r.total_gross', 'r.total_deductions', 'r.total_net'])
            ->map(fn ($r) => [
                'period' => $r->name, 'type' => $r->type, 'pay_date' => $r->pay_date, 'status' => $r->status, 'employees' => (int) $r->employee_count,
                'currency' => $r->currency, 'gross' => $this->ctx->money($r->total_gross, (int) $r->decimals),
                'deductions' => $this->ctx->money($r->total_deductions, (int) $r->decimals), 'net' => $this->ctx->money($r->total_net, (int) $r->decimals),
            ]);
    }
}
