<?php

namespace App\Services\Reports;

use App\Models\User;
use App\Services\AccessScope;
use App\Support\CompanyContext;
use App\Support\Settings;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Shared helpers for report definitions: scope, local time formatting, placement-on-date joins. */
class ReportContext
{
    public function __construct(
        public readonly AccessScope $scope,
        public readonly CompanyContext $company,
        public readonly Settings $settings,
    ) {}

    /** Employee ids the viewer may see for a domain (all permission, or direct reports via the team permission). */
    public function employeeIds(User $viewer, string $all, ?string $team): Builder
    {
        return DB::query()->fromSub($this->scope->employees($viewer, $all, $team, false)->select('employees.id')->toBase(), 'scoped')->select('scoped.id');
    }

    /** Join the employee's assignment (department, position) that was effective on the given date column. */
    public function joinPlacement(Builder $query, string $employeeColumn, string|Expression $dateColumn): Builder
    {
        return $query
            ->leftJoin('employee_assignments as ea', function ($j) use ($employeeColumn, $dateColumn) {
                $j->on('ea.employee_id', '=', $employeeColumn)
                    ->whereColumn('ea.effective_from', '<=', $dateColumn)
                    ->where(fn ($w) => $w->whereNull('ea.effective_to')->orWhereColumn('ea.effective_to', '>=', $dateColumn));
            })
            ->leftJoin('departments as dep', 'dep.id', '=', 'ea.department_id')
            ->leftJoin('positions as pos', 'pos.id', '=', 'ea.position_id');
    }

    public function time(?string $utc): ?string
    {
        return $utc ? $this->company->toLocal($utc)->format('H:i') : null;
    }

    public function dateTime(CarbonInterface|string|null $utc): ?string
    {
        return $utc ? $this->company->toLocal($utc)->format('Y-m-d H:i') : null;
    }

    public function hours(int|float|string|null $minutes): string
    {
        $m = (int) $minutes;

        return intdiv($m, 60).':'.str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT);
    }

    public function money(float|int|string|null $amount, int $decimals = 0): string
    {
        return number_format((float) $amount, $decimals, '.', ',');
    }
}
