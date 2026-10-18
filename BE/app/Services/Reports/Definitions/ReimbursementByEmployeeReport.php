<?php

namespace App\Services\Reports\Definitions;

use App\Models\User;
use App\Services\Reports\ReportFilters;
use Illuminate\Support\Collection;

class ReimbursementByEmployeeReport extends ReimbursementReport
{
    public function key(): string
    {
        return 'reimbursements.by_employee';
    }

    public function title(): string
    {
        return 'Reimbursements by employee';
    }

    public function description(): string
    {
        return 'Claim counts and amounts per employee.';
    }

    public function columns(): array
    {
        return ['employee_number' => 'Employee no.', 'employee' => 'Employee', 'claims' => 'Claims', 'submitted_total' => 'Submitted', 'approved_total' => 'Approved', 'paid_total' => 'Paid'];
    }

    public function rows(ReportFilters $f, User $viewer): Collection
    {
        return $this->grouped($f, $viewer, ['e.id', 'e.employee_number', 'e.full_name'], ['e.id', 'e.employee_number', 'e.full_name'], ['e.full_name', 'e.id'])
            ->map(fn ($r) => [
                'employee_number' => $r->employee_number, 'employee' => $r->full_name, 'claims' => (int) $r->claims,
                'submitted_total' => $this->money($r->submitted_total), 'approved_total' => $this->money($r->approved_total), 'paid_total' => $this->money($r->paid_total),
            ]);
    }
}
