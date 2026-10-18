<?php

namespace App\Services\Reports;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Reports\Definitions\AbsentReport;
use App\Services\Reports\Definitions\AttendanceByDepartmentReport;
use App\Services\Reports\Definitions\AttendanceSummaryReport;
use App\Services\Reports\Definitions\DailyAttendanceReport;
use App\Services\Reports\Definitions\ExpiredDocumentsReport;
use App\Services\Reports\Definitions\ExpiringDocumentsReport;
use App\Services\Reports\Definitions\LateAttendanceReport;
use App\Services\Reports\Definitions\LeaveRequestsReport;
use App\Services\Reports\Definitions\LeaveUsageReport;
use App\Services\Reports\Definitions\PayrollDetailReport;
use App\Services\Reports\Definitions\PayrollSummaryReport;
use App\Support\CompanyContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Catalogue, authorization and export of reports. Adding a report = write a Report class and list it in DEFINITIONS.
 */
class ReportService
{
    /** @var list<class-string<Report>> */
    private const DEFINITIONS = [
        DailyAttendanceReport::class, AttendanceSummaryReport::class, AttendanceByDepartmentReport::class, LateAttendanceReport::class, AbsentReport::class,
        LeaveUsageReport::class, LeaveRequestsReport::class,
        PayrollSummaryReport::class, PayrollDetailReport::class,
        ExpiringDocumentsReport::class, ExpiredDocumentsReport::class,
        Definitions\ReimbursementRequestsReport::class, Definitions\ReimbursementByEmployeeReport::class, Definitions\ReimbursementByDepartmentReport::class, Definitions\ReimbursementByCategoryReport::class, Definitions\ReimbursementByStatusReport::class,
    ];

    /** Hard cap on rows per run, so a report can never exhaust memory or produce an unusable file. */
    public const MAX_ROWS = 20000;

    public const GROUP_LABELS = ['attendance' => 'Attendance', 'leave' => 'Leave', 'payroll' => 'Payroll', 'documents' => 'Documents'];

    public function __construct(private readonly CompanyContext $context, private readonly ActivityLogger $activity) {}

    /** @return Collection<string, Report> reports the viewer may run, keyed by report key */
    public function available(User $viewer): Collection
    {
        return collect(self::DEFINITIONS)->map(fn (string $class) => app($class))
            ->filter(fn (Report $r) => $r->allowedFor($viewer))->keyBy(fn (Report $r) => $r->key());
    }

    /** Unknown and forbidden reports look the same (404) so the catalogue is not enumerable. */
    public function find(string $key, User $viewer): Report
    {
        return $this->available($viewer)->get($key)
            ?? throw ApiException::make('NOT_FOUND', 'The requested resource was not found.', 404);
    }

    /** @param array<string, mixed> $input */
    public function filters(array $input): ReportFilters
    {
        $filters = ReportFilters::fromInput($input, $this->context->today()->toDateString());

        $from = CarbonImmutable::parse($filters->from);
        $to = CarbonImmutable::parse($filters->to);
        if ($to->lessThan($from)) {
            throw ApiException::make('INVALID_DATE_RANGE', 'The end date must be on or after the start date.');
        }
        if ($from->diffInDays($to) > ReportFilters::MAX_DAYS) {
            throw ApiException::make('DATE_RANGE_TOO_LONG', 'Choose a period of at most '.ReportFilters::MAX_DAYS.' days.');
        }

        return $filters;
    }

    /** @return array{rows: Collection<int, array<string, mixed>>, totals: ?array<string, mixed>, truncated: bool} */
    public function run(Report $report, ReportFilters $filters, User $viewer): array
    {
        $rows = $report->rows($filters, $viewer);
        $truncated = $rows->count() > self::MAX_ROWS;

        return [
            'rows' => $truncated ? $rows->take(self::MAX_ROWS)->values() : $rows->values(),
            'totals' => $report->totals($rows),
            'truncated' => $truncated,
        ];
    }

    public function csv(Report $report, ReportFilters $filters, User $viewer): StreamedResponse
    {
        $result = $this->run($report, $filters, $viewer);
        $this->audit($report, $filters, $viewer, 'csv', $result['rows']->count());

        $columns = $report->columns();
        $name = str_replace('.', '-', $report->key()).'_'.$filters->from.'_'.$filters->to.'.csv';

        return response()->streamDownload(function () use ($result, $columns) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads accents correctly
            fputcsv($out, array_values($columns));
            foreach ($result['rows'] as $row) {
                fputcsv($out, array_map(fn ($key) => $this->safeCell($row[$key] ?? ''), array_keys($columns)));
            }
            if ($totals = $result['totals']) {
                fputcsv($out, array_map(fn ($key) => $this->safeCell($totals[$key] ?? ''), array_keys($columns)));
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function pdf(Report $report, ReportFilters $filters, User $viewer): Response
    {
        $result = $this->run($report, $filters, $viewer);
        $this->audit($report, $filters, $viewer, 'pdf', $result['rows']->count());

        $company = $this->context->company();
        $pdf = Pdf::loadView('reports.pdf', [
            'report' => $report, 'rows' => $result['rows'], 'totals' => $result['totals'], 'truncated' => $result['truncated'], 'filters' => $filters,
            'company' => $company->name, 'generatedAt' => $this->context->now()->format('Y-m-d H:i').' '.$this->context->timezone(), 'generatedBy' => $viewer->name,
        ])->setPaper('a4', count($report->columns()) > 7 ? 'landscape' : 'portrait');

        return $pdf->download(str_replace('.', '-', $report->key()).'_'.$filters->from.'_'.$filters->to.'.pdf');
    }

    /** Spreadsheet apps execute cells starting with = + - @ as formulas; prefix them so data stays data. */
    private function safeCell(mixed $value): string
    {
        $text = (string) $value;

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($text) ? "'".$text : $text;
    }

    private function audit(Report $report, ReportFilters $filters, User $viewer, string $format, int $rows): void
    {
        $this->activity->log('report.exported', 'report', null, ['report' => $report->key(), 'format' => $format, 'rows' => $rows] + $filters->toArray(), actor: $viewer);
    }
}
