<?php

namespace App\Services\Reports\Definitions;

use App\Models\User;
use App\Services\Reports\ReportFilters;
use Illuminate\Support\Collection;

class ReimbursementByStatusReport extends ReimbursementReport
{
    public function key(): string
    {
        return 'reimbursements.by_status';
    }

    public function title(): string
    {
        return 'Reimbursements by status';
    }

    public function description(): string
    {
        return 'Where claims stand: waiting for review, approved, scheduled, paid, rejected.';
    }

    public function filters(): array
    {
        return ['from', 'to', 'department_id', 'employee_id'];
    }

    public function columns(): array
    {
        return ['status' => 'Status', 'claims' => 'Claims', 'submitted_total' => 'Submitted', 'approved_total' => 'Approved', 'paid_total' => 'Paid'];
    }

    public function rows(ReportFilters $f, User $viewer): Collection
    {
        return $this->grouped($f, $viewer, ['r.status'], ['r.status'], ['r.status'])
            ->map(fn ($r) => [
                'status' => $r->status, 'claims' => (int) $r->claims,
                'submitted_total' => $this->money($r->submitted_total), 'approved_total' => $this->money($r->approved_total), 'paid_total' => $this->money($r->paid_total),
            ]);
    }
}
