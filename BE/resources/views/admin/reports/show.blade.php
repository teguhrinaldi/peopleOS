@extends('layouts.admin')
@section('title', $report->title())

@section('content')
@php
    $declared = $report->filters();
    $f = $result['filters'];
    $columns = $report->columns();
    $numeric = $report->numericColumns();
    $totals = $result['totals'];
    $labels = ['department_id' => 'Department', 'employee_id' => 'Employee', 'leave_type_id' => 'Leave type', 'period_id' => 'Payroll period', 'status' => 'Status'];
    $current = ['department_id' => $f->departmentId, 'employee_id' => $f->employeeId, 'leave_type_id' => $f->leaveTypeId, 'period_id' => $f->periodId, 'status' => $f->status];
    $exportQuery = $f->toArray();
    $maxBar = $bars ? max(1, max(array_column($bars['bars'], 'value'))) : 1;
@endphp
<x-page-header :title="$report->title()" :subtitle="$report->description()" :breadcrumbs="[['Reports', route('admin.reports')], [$report->title()]]">
    <x-button variant="outline" icon="download" :href="route('admin.reports.export', ['key' => $report->key(), 'format' => 'csv'] + $exportQuery)">CSV</x-button>
    <x-button variant="outline" icon="file-text" :href="route('admin.reports.export', ['key' => $report->key(), 'format' => 'pdf'] + $exportQuery)">PDF</x-button>
</x-page-header>

<form method="GET" action="{{ route('admin.reports.show', $report->key()) }}" class="card mb-6 grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4" aria-label="Report filters">
    @if (in_array('from', $declared, true))
        <x-input name="from" type="date" label="From" :value="$f->from" />
        <x-input name="to" type="date" label="To" :value="$f->to" />
    @endif
    @if (in_array('days', $declared, true))
        <x-input name="days" type="number" min="1" max="365" label="Expiring within (days)" :value="$f->days" placeholder="Company default" />
    @endif
    @foreach ($labels as $name => $label)
        @if (in_array($name, $declared, true))
            <x-select :name="$name" :label="$label" :options="$options[$name] ?? []" :value="$current[$name]" :placeholder="$name === 'period_id' ? null : 'All'" />
        @endif
    @endforeach
    <div class="flex items-end gap-2"><x-button type="submit" icon="search">Run report</x-button>
        <a href="{{ route('admin.reports.show', $report->key()) }}" class="btn btn-md btn-text">Reset</a></div>
</form>

<section class="mb-6 grid grid-cols-2 gap-4 xl:grid-cols-4" aria-label="Summary">
    @foreach ($tiles as $tile)
        <div class="card p-4"><p class="text-[12.5px] font-medium text-ink-2">{{ $tile['label'] }}</p><p class="mt-1 text-xl font-bold tabular-nums text-ink">{{ $tile['value'] }}</p></div>
    @endforeach
</section>

@if ($bars)
    <x-card :title="$bars['title']" class="mb-6">
        <ul class="space-y-2.5" aria-label="{{ $bars['title'] }}">
            @foreach ($bars['bars'] as $bar)
                <li class="grid grid-cols-[minmax(80px,160px)_1fr_auto] items-center gap-3 text-[13px]">
                    <span class="truncate text-ink-2" title="{{ $bar['label'] }}">{{ $bar['label'] }}</span>
                    <svg viewBox="0 0 100 8" preserveAspectRatio="none" class="h-2.5 w-full" role="img" aria-label="{{ $bar['label'] }}: {{ $bar['value'] }} {{ $bars['unit'] }}"><rect width="100" height="8" rx="4" class="fill-[var(--surface-3)]" /><rect width="{{ $bar['value'] > 0 ? max(1.5, $bar['value'] / $maxBar * 100) : 0 }}" height="8" rx="4" class="fill-[var(--primary)]" /></svg>
                    <span class="w-14 text-right font-semibold tabular-nums text-ink">{{ rtrim(rtrim(number_format($bar['value'], 1), '0'), '.') }}</span>
                </li>
            @endforeach
        </ul>
    </x-card>
@endif

@if ($result['truncated'])
    <x-alert tone="warning" class="mb-4" title="Result limited">This report has more rows than the export limit, so only the first {{ number_format($result['total']) }} are included. Narrow the dates or filters to see everything.</x-alert>
@elseif ($result['total'] > $screenRows)
    <x-alert tone="info" class="mb-4">Showing the first {{ number_format($screenRows) }} of {{ number_format($result['total']) }} rows. CSV and PDF exports contain all {{ number_format($result['total']) }}.</x-alert>
@endif

<x-data-table :empty="$result['shown']->isEmpty()" emptyIcon="chart-column" emptyTitle="No rows for these filters"
    :emptyText="in_array('period_id', $declared, true) && ! $f->periodId ? 'Create and generate a payroll period first.' : 'Try a wider date range or remove a filter.'" class="dt-sticky">
    <x-slot:head>
        @foreach ($columns as $key => $label)<th scope="col" @class(['text-right' => in_array($key, $numeric, true), 'whitespace-nowrap'])>{{ $label }}</th>@endforeach
    </x-slot:head>
    @foreach ($result['shown'] as $row)
        <tr>
            @foreach ($columns as $key => $label)
                <td @class(['text-right tabular-nums' => in_array($key, $numeric, true), 'whitespace-nowrap' => $loop->first])>{{ ($row[$key] ?? '') === '' || ($row[$key] ?? null) === null ? '—' : $row[$key] }}</td>
            @endforeach
        </tr>
    @endforeach
    @if ($totals)
        <tr class="bg-surface-2 font-semibold">
            @foreach ($columns as $key => $label)
                <td @class(['text-right tabular-nums' => in_array($key, $numeric, true)])>{{ $totals[$key] ?? '' }}</td>
            @endforeach
        </tr>
    @endif
</x-data-table>
@endsection
