<?php

namespace App\Services\Admin;

use App\Enums\AttendanceStatus;
use App\Enums\LeaveStatus;
use App\Enums\PayrollStatus;
use App\Exceptions\ApiException;
use App\Models\Department;
use App\Models\LeaveType;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Services\AccessScope;
use App\Services\Reports\Report;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportService;
use App\Support\CompanyContext;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Web side of the report catalogue. All data, scoping, totals and exports come from ReportService; this class only
 * prepares the filter form, trims the on-screen table and derives summary tiles from the very same rows.
 */
class ReportDesk
{
    /** Rows drawn on screen. Exports always contain every row (up to ReportService::MAX_ROWS). */
    public const SCREEN_ROWS = 500;

    private const GROUP_ICONS = ['attendance' => 'clock', 'leave' => 'calendar-days', 'payroll' => 'wallet', 'documents' => 'folder'];

    public function __construct(
        private readonly ReportService $reports,
        private readonly CompanyContext $context,
        private readonly AccessScope $scope,
    ) {}

    /** @return array<string, array{label: string, icon: string, reports: Collection<int, Report>}> only what the viewer may run */
    public function catalogue(User $viewer): array
    {
        $out = [];
        foreach ($this->reports->available($viewer)->groupBy(fn (Report $r) => $r->group()) as $group => $reports) {
            $out[$group] = ['label' => ReportService::GROUP_LABELS[$group] ?? ucfirst($group), 'icon' => self::GROUP_ICONS[$group] ?? 'file-text', 'reports' => $reports->values()];
        }

        return $out;
    }

    /** A report the viewer may run, or null (unknown and forbidden look the same). */
    public function find(User $viewer, string $key): ?Report
    {
        return $this->reports->available($viewer)->get($key);
    }

    /**
     * Resolve the filters the report declares from the request and run it.
     *
     * @param  array<string, mixed>  $input
     * @return array{filters: ReportFilters, rows: Collection, shown: Collection, totals: ?array, truncated: bool, total: int}
     */
    public function run(User $viewer, Report $report, array $input): array
    {
        $filters = $this->resolveFilters($report, $input);
        $result = $this->reports->run($report, $filters, $viewer);

        return [
            'filters' => $filters,
            'rows' => $result['rows'],
            'shown' => $result['rows']->take(self::SCREEN_ROWS)->values(),
            'totals' => $result['totals'],
            'truncated' => $result['truncated'],
            'total' => $result['rows']->count(),
        ];
    }

    /** Build validated filters for a screen run or an export (same code path, so they always match). */
    public function resolveFilters(Report $report, array $input): ReportFilters
    {
        $declared = $report->filters();
        $input = array_intersect_key($input, array_flip($declared));
        if (in_array('period_id', $declared, true) && empty($input['period_id'])) {
            $input['period_id'] = $this->latestPeriodId();
        }

        try {
            return $this->reports->filters($input);
        } catch (ApiException $e) {
            if (in_array($e->errorCode, ['INVALID_DATE_RANGE', 'DATE_RANGE_TOO_LONG'], true)) {
                throw ValidationException::withMessages(['to' => $e->getMessage()]);
            }
            throw $e;
        }
    }

    public function csv(User $viewer, Report $report, ReportFilters $filters)
    {
        return $this->reports->csv($report, $filters, $viewer);
    }

    public function pdf(User $viewer, Report $report, ReportFilters $filters)
    {
        return $this->reports->pdf($report, $filters, $viewer);
    }

    /** Options for each filter control the report declares. @return array<string, array<string|int, string>> */
    public function options(User $viewer, Report $report): array
    {
        $declared = $report->filters();
        $options = [];
        if (in_array('department_id', $declared, true)) {
            $options['department_id'] = Department::query()->where('company_id', $this->context->id())->orderBy('name')->pluck('name', 'id')->all();
        }
        if (in_array('employee_id', $declared, true)) {
            [$all, $team] = $report->group() === 'leave' ? ['leave.view', 'leave.view_team'] : ['attendance.view', 'attendance.view_team'];
            $options['employee_id'] = $this->scope->employees($viewer, $all, $team, false)->orderBy('full_name')->get(['employees.id', 'full_name', 'employee_number'])
                ->mapWithKeys(fn ($e) => [$e->id => $e->full_name.' ('.$e->employee_number.')'])->all();
        }
        if (in_array('leave_type_id', $declared, true)) {
            $options['leave_type_id'] = LeaveType::query()->where('company_id', $this->context->id())->orderBy('name')->pluck('name', 'id')->all();
        }
        if (in_array('period_id', $declared, true)) {
            $options['period_id'] = PayrollPeriod::query()->where('company_id', $this->context->id())->orderByDesc('start_date')->pluck('name', 'id')->all();
        }
        if (in_array('status', $declared, true)) {
            $options['status'] = match ($report->key()) {
                'attendance.daily' => ['LATE' => 'Late'] + collect(AttendanceStatus::cases())->mapWithKeys(fn ($s) => [$s->value => ucfirst(strtolower(str_replace('_', ' ', $s->value)))])->all(),
                'leave.requests' => collect(LeaveStatus::cases())->reject(fn ($s) => $s === LeaveStatus::Draft)->mapWithKeys(fn ($s) => [$s->value => ucfirst(strtolower($s->value))])->all(),
                'payroll.summary' => collect(PayrollStatus::cases())->mapWithKeys(fn ($s) => [$s->value => ucfirst(strtolower($s->value))])->all(),
                default => [],
            };
        }

        return $options;
    }

    /**
     * Summary tiles computed from the same rows and totals shown in the table: the row count plus every numeric
     * total the report itself declares. Nothing is estimated or invented.
     *
     * @param  array{rows: Collection, totals: ?array, total: int}  $result
     * @return list<array{label: string, value: string}>
     */
    public function tiles(Report $report, array $result): array
    {
        $tiles = [['label' => 'Rows', 'value' => number_format($result['total'])]];
        $columns = $report->columns();
        foreach ((array) $result['totals'] as $key => $value) {
            if ($value === null || $value === '' || ! isset($columns[$key])) {
                continue;
            }
            if (in_array($key, $report->numericColumns(), true) || is_string($value) && preg_match('/^\d+ /', $value)) {
                $tiles[] = ['label' => $columns[$key], 'value' => (string) $value];
            }
        }

        return array_slice($tiles, 0, 4);
    }

    /**
     * A simple horizontal bar series where it is meaningful: late arrivals per department, leave days per leave type.
     *
     * @return ?array{title: string, unit: string, bars: list<array{label: string, value: float}>}
     */
    public function bars(Report $report, Collection $rows): ?array
    {
        if ($rows->isEmpty()) {
            return null;
        }
        if ($report->key() === 'attendance.by_department') {
            return ['title' => 'Late arrivals by department', 'unit' => 'late', 'bars' => $rows->map(fn ($r) => ['label' => (string) ($r['department'] ?? 'Unassigned'), 'value' => (float) $r['late']])->sortByDesc('value')->values()->all()];
        }
        if ($report->key() === 'leave.usage') {
            $bars = $rows->groupBy('leave_type')->map(fn ($g, $type) => ['label' => (string) $type, 'value' => round((float) $g->sum('days'), 1)])->sortByDesc('value')->values()->all();

            return ['title' => 'Leave days by type', 'unit' => 'days', 'bars' => $bars];
        }

        return null;
    }

    /** Right-aligned numeric columns need a text alignment hint; reports declare them. */
    public function latestPeriodId(): ?int
    {
        return PayrollPeriod::query()->where('company_id', $this->context->id())->whereHas('run', fn ($q) => $q->whereNotNull('generated_at'))
            ->orderByDesc('start_date')->value('id');
    }
}
