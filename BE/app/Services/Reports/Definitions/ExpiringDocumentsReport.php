<?php

namespace App\Services\Reports\Definitions;

use App\Models\User;
use App\Services\Reports\Report;
use App\Services\Reports\ReportFilters;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Documents that expire within `days` (default: the company's expiring window) and have not expired yet. Archived and deleted are excluded. */
class ExpiringDocumentsReport extends Report
{
    public function key(): string
    {
        return 'documents.expiring';
    }

    public function title(): string
    {
        return 'Expiring documents';
    }

    public function group(): string
    {
        return 'documents';
    }

    public function description(): string
    {
        return 'Documents that expire soon, soonest first.';
    }

    public function permissions(): array
    {
        return ['document.view'];
    }

    public function filters(): array
    {
        return ['days', 'department_id'];
    }

    public function columns(): array
    {
        return ['employee' => 'Employee', 'department' => 'Department', 'type' => 'Type', 'title' => 'Title', 'expiry_date' => 'Expires', 'days_left' => 'Days left'];
    }

    public function numericColumns(): array
    {
        return ['days_left'];
    }

    public function rows(ReportFilters $f, User $viewer): Collection
    {
        $today = $this->ctx->company->today();
        $window = $f->days ?: $this->ctx->settings->int('documents.expiring_days');

        return $this->query($f, $viewer)->whereNull('d.archived_at')
            ->whereBetween('d.expiry_date', [$today->toDateString(), $today->addDays($window)->toDateString()])
            ->orderBy('d.expiry_date')->get($this->select())
            ->map(fn ($r) => $this->row($r, $today));
    }

    protected function query(ReportFilters $f, User $viewer)
    {
        $q = DB::table('documents as d')->join('document_types as t', 't.id', '=', 'd.document_type_id')->leftJoin('employees as e', 'e.id', '=', 'd.employee_id')
            ->where('d.company_id', $this->ctx->company->id())->whereNull('d.deleted_at');
        $this->ctx->joinPlacement($q, 'd.employee_id', DB::raw('CURRENT_DATE'));
        if ($f->departmentId) {
            $q->where('ea.department_id', $f->departmentId);
        }

        return $q;
    }

    /** @return list<string> */
    protected function select(): array
    {
        return ['e.full_name', 'dep.name as department', 't.name as type', 'd.title', 'd.expiry_date'];
    }

    /** @return array<string, scalar|null> */
    protected function row(object $r, $today): array
    {
        return [
            'employee' => $r->full_name ?? 'Company', 'department' => $r->department, 'type' => $r->type, 'title' => $r->title, 'expiry_date' => $r->expiry_date,
            'days_left' => (int) $today->diffInDays($r->expiry_date, false),
        ];
    }
}
