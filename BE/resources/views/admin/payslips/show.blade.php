@extends('layouts.admin')
@section('title', $payslip->payslip_number)

@section('content')
@php use App\Support\DateDisplay; @endphp
<x-page-header :title="'Payslip '.$payslip->payslip_number" :subtitle="$item->employee_name.' · '.$period->name" :breadcrumbs="[['Payslips', route('admin.payslips')], [$payslip->payslip_number]]">
    @if ($payslip->hasFile())
        <x-button variant="outline" icon="eye" :href="route('admin.payslips.pdf', $payslip)" target="_blank" rel="noopener">View PDF</x-button>
        <x-button icon="download" :href="route('admin.payslips.pdf', [$payslip, 'download' => 1])">Download</x-button>
    @endif
    @if ($canRegenerate)
        <form method="POST" action="{{ route('admin.payslips.regenerate', $payslip) }}">@csrf
            <x-button type="submit" variant="outline" icon="refresh-cw">{{ $payslip->hasFile() ? 'Re-check PDF' : 'Generate missing PDF' }}</x-button>
        </form>
    @endif
</x-page-header>

@unless ($payslip->hasFile())
    <x-alert tone="warning" class="mb-6" title="The PDF file is missing">It is generated again from the stored payslip data the first time someone opens it, or right now with the button above.</x-alert>
@endunless

<div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    <x-card title="Payslip" class="lg:col-span-1">
        <x-kv :items="[
            'Payslip number' => $payslip->payslip_number,
            'Issued' => DateDisplay::moment($payslip->issued_at),
            'Period' => $period->name,
            'Pay period' => DateDisplay::range($period->start_date, $period->end_date),
            'Status' => ucfirst(strtolower($period->status->value)),
            'Employee notified' => $payslip->notified_at ? DateDisplay::moment($payslip->notified_at) : 'Not yet',
        ]" />
        @if ($period->isAdjustment())<p class="mt-4 rounded-lg bg-info-soft px-3 py-2 text-xs text-info-ink">Adjustment payslip: {{ $period->adjustment_reason }}</p>@endif
    </x-card>
    <x-card title="Pay details" class="lg:col-span-2">
        @include('admin.payroll._item', ['canPayslip' => false])
    </x-card>
</div>
@endsection
