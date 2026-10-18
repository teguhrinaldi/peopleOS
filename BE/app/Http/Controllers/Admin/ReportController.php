<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReportRunRequest;
use App\Services\Admin\ReportDesk;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Report catalogue, on-screen run and CSV/PDF export. Reports the viewer may not run answer 404, like unknown ones. */
class ReportController extends Controller
{
    public function __construct(private readonly ReportDesk $desk) {}

    public function index(Request $request): View
    {
        return view('admin.reports.index', ['catalogue' => $this->desk->catalogue($request->user())]);
    }

    public function show(ReportRunRequest $request, string $key): View
    {
        $report = $this->desk->find($request->user(), $key) ?? abort(404);
        $result = $this->desk->run($request->user(), $report, $request->validated());

        return view('admin.reports.show', [
            'report' => $report,
            'result' => $result,
            'options' => $this->desk->options($request->user(), $report),
            'tiles' => $this->desk->tiles($report, $result),
            'bars' => $this->desk->bars($report, $result['rows']),
            'screenRows' => ReportDesk::SCREEN_ROWS,
            'query' => $request->validated(),
        ]);
    }

    public function export(ReportRunRequest $request, string $key, string $format)
    {
        abort_unless(in_array($format, ['csv', 'pdf'], true), 404);
        $report = $this->desk->find($request->user(), $key) ?? abort(404);
        $filters = $this->desk->resolveFilters($report, $request->validated());

        return $format === 'csv' ? $this->desk->csv($request->user(), $report, $filters) : $this->desk->pdf($request->user(), $report, $filters);
    }
}
