<?php

namespace App\Services\Reports;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * One report: who may run it, which filters it takes, its columns and its rows.
 * Rows are plain scalar arrays keyed by column key, so the same data feeds the screen, CSV and PDF.
 * Every report reads real tables; there are no precomputed or invented numbers.
 */
abstract class Report
{
    public function __construct(protected readonly ReportContext $ctx) {}

    abstract public function key(): string;

    abstract public function title(): string;

    /** attendance | leave | payroll | documents */
    abstract public function group(): string;

    abstract public function description(): string;

    /** Permissions the viewer needs in addition to report.view (any one of them is enough). */
    abstract public function permissions(): array;

    /** @return list<string> filter names this report understands */
    abstract public function filters(): array;

    /** @return array<string, string> column key => label */
    abstract public function columns(): array;

    /** @return Collection<int, array<string, scalar|null>> */
    abstract public function rows(ReportFilters $filters, User $viewer): Collection;

    /** Totals row shown under the table, keyed by column key (null = none). */
    public function totals(Collection $rows): ?array
    {
        return null;
    }

    /** Right-aligned numeric columns (for UI and PDF). */
    public function numericColumns(): array
    {
        return [];
    }

    public function allowedFor(User $viewer): bool
    {
        return $viewer->hasPermission('report.view') && $viewer->hasAnyPermission(...$this->permissions());
    }
}
