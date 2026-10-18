<?php

namespace App\Services\Reports\Definitions;

use App\Models\User;
use App\Services\Reports\ReportFilters;
use Illuminate\Support\Collection;

/** Documents whose expiry date has passed (the expiry date is the last valid day) and that are not archived. */
class ExpiredDocumentsReport extends ExpiringDocumentsReport
{
    public function key(): string
    {
        return 'documents.expired';
    }

    public function title(): string
    {
        return 'Expired documents';
    }

    public function description(): string
    {
        return 'Documents past their expiry date that still need renewing or archiving, longest overdue first.';
    }

    public function filters(): array
    {
        return ['department_id'];
    }

    public function columns(): array
    {
        return ['employee' => 'Employee', 'department' => 'Department', 'type' => 'Type', 'title' => 'Title', 'expiry_date' => 'Expired on', 'days_overdue' => 'Days overdue'];
    }

    public function numericColumns(): array
    {
        return ['days_overdue'];
    }

    public function rows(ReportFilters $f, User $viewer): Collection
    {
        $today = $this->ctx->company->today();

        return $this->query($f, $viewer)->whereNull('d.archived_at')->where('d.expiry_date', '<', $today->toDateString())
            ->orderBy('d.expiry_date')->get($this->select())
            ->map(function ($r) use ($today) {
                $row = $this->row($r, $today);
                $row['days_overdue'] = abs($row['days_left']);
                unset($row['days_left']);

                return $row;
            });
    }
}
