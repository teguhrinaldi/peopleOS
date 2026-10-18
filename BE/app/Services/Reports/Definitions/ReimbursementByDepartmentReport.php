<?php

namespace App\Services\Reports\Definitions;

use App\Models\User;
use App\Services\Reports\ReportFilters;
use Illuminate\Support\Collection;

class ReimbursementByDepartmentReport extends ReimbursementReport
{
    public function key(): string
    {
        return 'reimbursements.by_department';
    }

    public function title(): string
    {
        return 'Reimbursements by department';
    }

    public function description(): string
    {
        return 'Claim counts and amounts per department (placement on the expense date).';
    }

    public function filters(): array
    {
        return ['from', 'to', 'status'];
    }

    public function columns(): array
    {
        return ['department' => 'Department', 'claims' => 'Claims', 'submitted_total' => 'Submitted', 'approved_total' => 'Approved', 'paid_total' => 'Paid'];
    }

    public function rows(ReportFilters $f, User $viewer): Collection
    {
        return $this->grouped($f, $viewer, ['dep.name as department'], ['dep.name'], ['dep.name'])
            ->map(fn ($r) => [
                'department' => $r->department ?? 'No department', 'claims' => (int) $r->claims,
                'submitted_total' => $this->money($r->submitted_total), 'approved_total' => $this->money($r->approved_total), 'paid_total' => $this->money($r->paid_total),
            ]);
    }
}
