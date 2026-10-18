@extends('layouts.admin')
@section('title', 'Attendance corrections')

@section('content')
@php
    use App\Support\Fmt;
    $ctx = app(\App\Support\CompanyContext::class);
    $reopen = old('_form') === 'correction';
    $isoTime = fn ($iso) => $iso ? Fmt::time(\Carbon\CarbonImmutable::parse($iso)) : null;
@endphp

<x-page-header title="Attendance" subtitle="Correction requests from employees (for example a forgotten check-out). Your own requests are handled by someone else."
    :breadcrumbs="[['Attendance', route('admin.attendance')], ['Corrections']]" />

@include('admin.attendance._tabs', ['active' => 'corrections'])

<div class="mb-4 flex gap-2">
    @foreach (['pending' => 'Waiting for review', 'decided' => 'Decided'] as $key => $label)
        <a href="{{ route('admin.attendance.corrections', ['tab' => $key]) }}" @class(['rounded-full px-3.5 py-1.5 text-[13px] font-semibold transition', 'bg-primary text-white' => $tab === $key, 'bg-surface text-ink-2 ring-1 ring-line hover:text-ink' => $tab !== $key])>{{ $label }}</a>
    @endforeach
</div>

<x-data-table :empty="$corrections->isEmpty()" emptyIcon="clipboard-check" :emptyTitle="$tab === 'pending' ? 'Nothing waiting for review' : 'No decided corrections yet'" emptyText="Requests from the mobile app appear here.">
    <x-slot:head><th>Employee</th><th>Work date</th><th class="hidden md:table-cell">Recorded</th><th>Requested</th><th class="hidden lg:table-cell">Reason</th><th>{{ $tab === 'pending' ? 'Requested' : 'Decision' }}</th>@if ($tab === 'pending')<th class="w-44"><span class="sr-only">Actions</span></th>@endif</x-slot:head>
    @foreach ($corrections as $c)
        @php
            $snap = $c->original_snapshot ?? [];
            $approve = ['name' => 'correction-form', 'data' => ['action' => route('admin.attendance.corrections.decide', $c), 'decision' => 'approve', 'title' => 'Approve correction for '.$c->employee->full_name, 'note' => '']];
            $reject = ['name' => 'correction-form', 'data' => ['action' => route('admin.attendance.corrections.decide', $c), 'decision' => 'reject', 'title' => 'Reject correction for '.$c->employee->full_name, 'note' => '']];
        @endphp
        <tr>
            <td><span class="flex items-center gap-3"><x-avatar :name="$c->employee->full_name" :src="$c->employee->photo_path" size="sm" />
                <span><span class="block font-semibold text-ink">{{ $c->employee->full_name }}</span><span class="block text-xs text-ink-3">{{ $c->employee->employee_number }}</span></span></span></td>
            <td class="whitespace-nowrap">
                @if ($c->attendance_id)<a href="{{ route('admin.attendance.show', $c->attendance_id) }}" class="font-medium text-primary-ink hover:underline">{{ $c->work_date->format('D, d M Y') }}</a>
                @else {{ $c->work_date->format('D, d M Y') }} @endif
            </td>
            <td class="hidden whitespace-nowrap tabular-nums text-xs md:table-cell">in {{ $isoTime($snap['check_in_at'] ?? null) ?? '—' }}<br>out {{ $isoTime($snap['check_out_at'] ?? null) ?? '—' }}</td>
            <td class="whitespace-nowrap tabular-nums text-xs font-semibold text-ink">in {{ Fmt::time($c->requested_check_in_at) ?? 'unchanged' }}<br>out {{ Fmt::time($c->requested_check_out_at) ?? 'unchanged' }}</td>
            <td class="hidden max-w-72 lg:table-cell"><span class="line-clamp-2">{{ $c->reason }}</span></td>
            <td class="whitespace-nowrap text-xs">
                @if ($tab === 'pending')
                    {{ $ctx->toLocal($c->created_at)->format('d M, H:i') }}
                @else
                    <x-badge :status="$c->status" />
                    <span class="mt-1 block text-ink-3">{{ $c->reviewer?->name ?? '—' }}{{ $c->reviewed_at ? ' · '.$ctx->toLocal($c->reviewed_at)->format('d M, H:i') : '' }}</span>
                    @if ($c->review_note)<span class="block max-w-56 truncate text-ink-2" title="{{ $c->review_note }}">“{{ $c->review_note }}”</span>@endif
                @endif
            </td>
            @if ($tab === 'pending')
                <td class="whitespace-nowrap text-right">
                    <x-button variant="danger-soft" size="sm" :dispatch="$reject">Reject</x-button>
                    <x-button size="sm" :dispatch="$approve">Approve</x-button>
                </td>
            @endif
        </tr>
    @endforeach
    <x-slot:footer><x-pagination :paginator="$corrections" /></x-slot:footer>
</x-data-table>

<x-modal name="correction-form" title="Review correction" :open="$reopen" :initial="['action' => old('_action'), 'decision' => old('decision', 'approve'), 'note' => old('note', ''), 'title' => 'Review correction']">
    <form id="correction-form" method="POST" :action="data.action" class="space-y-4">
        @csrf
        <input type="hidden" name="_form" value="correction">
        <input type="hidden" name="_action" :value="data.action">
        <input type="hidden" name="decision" :value="data.decision">
        <p class="text-[13.5px] text-ink-2" x-show="data.decision === 'approve'">The requested times replace the recorded ones and late / worked time is recalculated.</p>
        <p class="text-[13.5px] text-ink-2" x-show="data.decision === 'reject'">The record goes back to how it was. The employee sees your reason.</p>
        <x-mfield name="note" as="textarea" label="Note" :rows="3" helper="Required when rejecting." />
    </form>
    <x-slot:footer>
        <x-button variant="outline" @click="hide()">Cancel</x-button>
        <button type="submit" form="correction-form" class="btn btn-md" :class="data.decision === 'reject' ? 'btn-danger' : 'btn-primary'" x-text="data.decision === 'reject' ? 'Reject' : 'Approve'"></button>
    </x-slot:footer>
</x-modal>
@endsection
