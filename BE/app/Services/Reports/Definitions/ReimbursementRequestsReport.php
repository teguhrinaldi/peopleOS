<?php

namespace App\Services\Reports\Definitions;

use App\Models\User;
use App\Services\Reports\ReportFilters;
use Illuminate\Support\Collection;

/** Every non-draft reimbursement claim in the window (by period / status / employee / department), one row per claim. */
class ReimbursementRequestsReport extends ReimbursementReport
{
    public function key(): string
    {
        return 'reimbursements.requests';
    }

    public function title(): string
    {
        return 'Reimbursement claims';
    }

    public function description(): string
    {
        return 'Every claim with category, amounts, status, reviewer and payment date.';
    }

    public function columns(): array
    {
        return ['request_number' => 'Request', 'employee_number' => 'Employee no.', 'employee' => 'Employee', 'department' => 'Department', 'category' => 'Category',
            'expense_date' => 'Expense date', 'submitted' => 'Submitted amount', 'approved' => 'Approved amount', 'status' => 'Status', 'submitted_at' => 'Submitted', 'paid_at' => 'Paid'];
    }

    public function numericColumns(): array
    {
        return ['submitted', 'approved'];
    }

    public function rows(ReportFilters $f, User $viewer): Collection
    {
        return $this->base($f, $viewer)->orderByDesc('r.expense_date')->orderByDesc('r.id')
            ->get(['r.request_number', 'e.employee_number', 'e.full_name', 'dep.name as department', 'c.name as category', 'r.expense_date', 'r.submitted_amount',
                'r.approved_amount', 'r.status', 'r.submitted_at', 'r.paid_at'])
            ->map(fn ($r) => [
                'request_number' => $r->request_number, 'employee_number' => $r->employee_number, 'employee' => $r->full_name, 'department' => $r->department,
                'category' => $r->category, 'expense_date' => $r->expense_date, 'submitted' => $this->money($r->submitted_amount),
                'approved' => $r->approved_amount === null ? null : $this->money($r->approved_amount), 'status' => $r->status,
                'submitted_at' => $this->ctx->dateTime($r->submitted_at), 'paid_at' => $this->ctx->dateTime($r->paid_at),
            ]);
    }

    public function totals(Collection $rows): ?array
    {
        return ['employee' => $rows->count().' claims', 'submitted' => $this->money($rows->sum('submitted')), 'approved' => $this->money($rows->sum('approved'))];
    }
}
