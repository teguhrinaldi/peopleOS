@extends('layouts.admin')
@section('title', 'Payslips')

@section('content')
@php
    use App\Services\Admin\PayrollDesk;
    use App\Support\DateDisplay;
    $active = collect($filters)->filter()->isNotEmpty();
@endphp

<x-page-header title="Payslips" subtitle="Issued when payroll is finalized. Employees see their own payslips in the mobile app." :breadcrumbs="[['Payroll'], ['Payslips']]" />

<x-filter-bar :action="route('admin.payslips')" :reset="route('admin.payslips')" :q="$filters['q'] ?? ''" placeholder="Search by employee, number or payslip" :active="$active" class="mb-4">
    <x-listbox class="w-full md:w-52"><select name="period_id" class="ctl" aria-label="Period">
        <option value="">All periods</option>
        @foreach ($periods as $id => $name)<option value="{{ $id }}" @selected((string) ($filters['period_id'] ?? '') === (string) $id)>{{ $name }}</option>@endforeach
    </select></x-listbox>
    <x-listbox class="w-full md:w-32"><select name="year" class="ctl" aria-label="Year">
        <option value="">All years</option>
        @foreach ($years as $y)<option value="{{ $y }}" @selected((string) ($filters['year'] ?? '') === (string) $y)>{{ $y }}</option>@endforeach
    </select></x-listbox>
</x-filter-bar>

<x-data-table :empty="$payslips->isEmpty()" emptyIcon="receipt" :emptyTitle="$active ? 'No payslips match' : 'No payslips issued yet'"
    :emptyText="$active ? 'Try another search or period.' : 'Payslips are issued automatically when a payroll period is finalized.'" class="dt-sticky">
    <x-slot:head>
        <th>Employee</th><th>Payslip</th><th class="hidden md:table-cell">Period</th><th class="text-right">Net pay</th><th class="hidden sm:table-cell">PDF</th><th class="w-24"><span class="sr-only">Actions</span></th>
    </x-slot:head>
    @foreach ($payslips as $s)
        <tr>
            <td><a href="{{ route('admin.payslips.show', $s) }}" class="block font-semibold text-ink hover:text-primary-ink">{{ $s->item->employee_name }}</a><span class="text-xs text-ink-3">{{ $s->item->employee_number }}@if ($s->item->department_name) &middot; {{ $s->item->department_name }}@endif</span></td>
            <td class="whitespace-nowrap"><span class="font-medium text-ink">{{ $s->payslip_number }}</span><span class="block text-xs text-ink-3">{{ DateDisplay::moment($s->issued_at) }}</span></td>
            <td class="hidden md:table-cell">{{ $s->period->name }}@if ($s->period->isAdjustment()) <x-badge tone="info" :dot="false">Adjustment</x-badge>@endif</td>
            <td class="text-right font-semibold tabular-nums text-ink">{{ PayrollDesk::money($s->item->net_pay, $s->item->run) }}</td>
            <td class="hidden sm:table-cell">@if ($s->hasFile())<x-badge tone="success">Ready</x-badge>@else<x-badge tone="warning">Missing</x-badge>@endif</td>
            <td class="text-right">
                <div class="flex justify-end gap-1">
                    <a href="{{ route('admin.payslips.pdf', $s) }}" target="_blank" rel="noopener" class="grid size-8 place-items-center rounded-lg text-ink-3 hover:bg-surface-3 hover:text-ink" aria-label="Open PDF for {{ $s->item->employee_name }}"><x-icon name="file-text" class="size-4" /></a>
                    <a href="{{ route('admin.payslips.show', $s) }}" class="grid size-8 place-items-center rounded-lg text-ink-3 hover:bg-surface-3 hover:text-ink" aria-label="Details for {{ $s->item->employee_name }}"><x-icon name="chevron-right" class="size-4" /></a>
                </div>
            </td>
        </tr>
    @endforeach
    <x-slot:footer><x-pagination :paginator="$payslips" /></x-slot:footer>
</x-data-table>
@endsection
