<?php

namespace App\Services\Reports\Definitions;

use App\Models\User;
use App\Services\Reports\Report;
use App\Services\Reports\ReportFilters;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Shared base of the reimbursement reports: the claims whose EXPENSE DATE falls in the window, never drafts, restricted to
 * the viewer's expense.view / expense.view_team scope. Concrete reports choose how to group.
 */
abstract class ReimbursementReport extends Report
{
    public function group(): string
    {
        return 'reimbursements';
    }

    public function permissions(): array
    {
        return ['expense.view', 'expense.view_team'];
    }

    public function filters(): array
    {
        return ['from', 'to', 'department_id', 'employee_id', 'status'];
    }

    protected function base(ReportFilters $f, User $viewer): Builder
    {
        $q = DB::table('expense_requests as r')
            ->join('employees as e', 'e.id', '=', 'r.employee_id')
            ->join('expense_categories as c', 'c.id', '=', 'r.expense_category_id')
            ->where('r.company_id', $this->ctx->company->id())
            ->where('r.status', '!=', 'DRAFT')
            ->whereBetween('r.expense_date', [$f->from, $f->to])
            ->whereIn('r.employee_id', $this->ctx->employeeIds($viewer, 'expense.view', 'expense.view_team'));
        $this->ctx->joinPlacement($q, 'r.employee_id', 'r.expense_date');

        if ($f->departmentId) {
            $q->where('ea.department_id', $f->departmentId);
        }
        if ($f->employeeId) {
            $q->where('r.employee_id', $f->employeeId);
        }
        if ($f->status) {
            $q->where('r.status', $f->status);
        }

        return $q;
    }

    /** Aggregates per group: count, submitted, approved (decided-in-favour claims) and paid totals. */
    protected function grouped(ReportFilters $f, User $viewer, array $groupSelect, array $groupBy, array $orderBy): Collection
    {
        $approvedStates = "('APPROVED','SCHEDULED_FOR_PAYMENT','PAID')";

        return $this->base($f, $viewer)
            ->groupBy($groupBy)->orderBy($orderBy[0])->orderBy($orderBy[1] ?? $orderBy[0])
            ->get(array_merge($groupSelect, [
                DB::raw('COUNT(*) as claims'),
                DB::raw('SUM(r.submitted_amount) as submitted_total'),
                DB::raw("COALESCE(SUM(CASE WHEN r.status IN {$approvedStates} THEN r.approved_amount END), 0) as approved_total"),
                DB::raw("COALESCE(SUM(CASE WHEN r.status = 'PAID' THEN r.approved_amount END), 0) as paid_total"),
            ]));
    }

    public function numericColumns(): array
    {
        return ['claims', 'submitted_total', 'approved_total', 'paid_total'];
    }

    public function totals(Collection $rows): ?array
    {
        return [
            'claims' => (string) $rows->sum('claims'),
            'submitted_total' => number_format((float) $rows->sum('submitted_total'), 2, '.', ''),
            'approved_total' => number_format((float) $rows->sum('approved_total'), 2, '.', ''),
            'paid_total' => number_format((float) $rows->sum('paid_total'), 2, '.', ''),
        ];
    }

    protected function money(mixed $v): string
    {
        return number_format((float) $v, 2, '.', '');
    }
}
