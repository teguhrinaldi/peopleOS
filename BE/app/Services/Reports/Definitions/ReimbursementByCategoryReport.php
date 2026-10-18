<?php

namespace App\Services\Reports\Definitions;

use App\Models\User;
use App\Services\Reports\ReportFilters;
use Illuminate\Support\Collection;

class ReimbursementByCategoryReport extends ReimbursementReport
{
    public function key(): string
    {
        return 'reimbursements.by_category';
    }

    public function title(): string
    {
        return 'Reimbursements by category';
    }

    public function description(): string
    {
        return 'Claim counts and amounts per expense category.';
    }

    public function columns(): array
    {
        return ['category' => 'Category', 'claims' => 'Claims', 'submitted_total' => 'Submitted', 'approved_total' => 'Approved', 'paid_total' => 'Paid'];
    }

    public function rows(ReportFilters $f, User $viewer): Collection
    {
        return $this->grouped($f, $viewer, ['c.id', 'c.name as category'], ['c.id', 'c.name'], ['c.name', 'c.id'])
            ->map(fn ($r) => [
                'category' => $r->category, 'claims' => (int) $r->claims,
                'submitted_total' => $this->money($r->submitted_total), 'approved_total' => $this->money($r->approved_total), 'paid_total' => $this->money($r->paid_total),
            ]);
    }
}
