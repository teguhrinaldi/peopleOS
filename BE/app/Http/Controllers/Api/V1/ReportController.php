<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Services\Reports\Report;
use App\Services\Reports\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Reports API. Authorization is the report's own rule (report.view + a domain permission); scope comes from AccessScope. */
class ReportController extends ApiController
{
    private const FILTER_RULES = [
        'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'department_id' => ['nullable', 'integer'],
        'employee_id' => ['nullable', 'integer'], 'status' => ['nullable', 'string', 'max:30'], 'period_id' => ['nullable', 'integer'],
        'days' => ['nullable', 'integer', 'min:1', 'max:365'], 'leave_type_id' => ['nullable', 'integer'],
    ];

    public function __construct(private readonly ReportService $reports) {}

    public function index(Request $request): JsonResponse
    {
        return $this->ok($this->reports->available($request->user())->map(fn (Report $r) => $this->describe($r))->values());
    }

    public function show(Request $request, string $key): JsonResponse
    {
        $report = $this->reports->find($key, $request->user());
        $filters = $this->reports->filters($request->validate(self::FILTER_RULES));
        $result = $this->reports->run($report, $filters, $request->user());

        // Pagination keeps API responses small; exports always contain every row.
        $perPage = $this->perPage($request, 50);
        $page = max(1, $request->integer('page', 1));
        $total = $result['rows']->count();

        return $this->ok([
            'report' => $this->describe($report),
            'filters' => $filters->toArray(),
            'rows' => $result['rows']->slice(($page - 1) * $perPage, $perPage)->values(),
            'totals' => $result['totals'],
            'truncated' => $result['truncated'],
        ], 200, ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))]);
    }

    public function export(Request $request, string $key, string $format)
    {
        abort_unless(in_array($format, ['csv', 'pdf'], true), 404);
        $report = $this->reports->find($key, $request->user());
        $filters = $this->reports->filters($request->validate(self::FILTER_RULES));

        return $format === 'csv'
            ? $this->reports->csv($report, $filters, $request->user())
            : $this->reports->pdf($report, $filters, $request->user());
    }

    /** @return array<string, mixed> */
    private function describe(Report $r): array
    {
        return [
            'key' => $r->key(), 'title' => $r->title(), 'group' => $r->group(), 'description' => $r->description(),
            'filters' => $r->filters(), 'columns' => $r->columns(), 'numeric_columns' => $r->numericColumns(),
        ];
    }
}
