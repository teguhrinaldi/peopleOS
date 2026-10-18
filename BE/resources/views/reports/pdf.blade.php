<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $report->title() }}</title>
<style>
    @page { margin: 28px 30px 40px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #0f172a; }
    .brand { font-size: 10px; font-weight: bold; color: #2563eb; letter-spacing: .5px; }
    h1 { font-size: 17px; margin: 4px 0 2px; color: #0f172a; }
    .meta { color: #64748b; font-size: 8.5px; margin-bottom: 10px; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #0f172a; color: #fff; text-align: left; padding: 5px 6px; font-size: 8.5px; }
    td { padding: 4px 6px; border-bottom: 1px solid #e2e8f0; }
    tr:nth-child(even) td { background: #f8fafc; }
    .num { text-align: right; }
    .totals td { font-weight: bold; background: #eff6ff; border-top: 1.5px solid #2563eb; }
    .note { margin-top: 8px; color: #b45309; font-size: 8.5px; }
    .empty { padding: 30px; text-align: center; color: #64748b; }
    .foot { position: fixed; bottom: -22px; left: 0; right: 0; font-size: 8px; color: #94a3b8; }
</style>
</head>
<body>
@php($numeric = $report->numericColumns())
<div class="brand">{{ $company }} · PeopleOS</div>
<h1>{{ $report->title() }}</h1>
<div class="meta">
    @if (in_array('from', $report->filters(), true)) Period {{ $filters->from }} to {{ $filters->to }} · @endif
    Generated {{ $generatedAt }} by {{ $generatedBy }} · {{ $rows->count() }} rows
</div>
@if ($rows->isEmpty())
    <div class="empty">No data for the selected filters.</div>
@else
<table>
    <thead><tr>
        @foreach ($report->columns() as $key => $label)
            <th class="{{ in_array($key, $numeric, true) ? 'num' : '' }}">{{ $label }}</th>
        @endforeach
    </tr></thead>
    <tbody>
    @foreach ($rows as $row)
        <tr>
            @foreach ($report->columns() as $key => $label)
                <td class="{{ in_array($key, $numeric, true) ? 'num' : '' }}">{{ $row[$key] ?? '' }}</td>
            @endforeach
        </tr>
    @endforeach
    @if ($totals)
        <tr class="totals">
            @foreach ($report->columns() as $key => $label)
                <td class="{{ in_array($key, $numeric, true) ? 'num' : '' }}">{{ $totals[$key] ?? '' }}</td>
            @endforeach
        </tr>
    @endif
    </tbody>
</table>
@endif
@if ($truncated)
    <div class="note">Only the first {{ number_format(\App\Services\Reports\ReportService::MAX_ROWS) }} rows are shown. Narrow the filters to see the rest.</div>
@endif
<div class="foot">PeopleOS · {{ $report->title() }} · confidential</div>
</body>
</html>
