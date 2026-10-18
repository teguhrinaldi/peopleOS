@extends('layouts.admin')
@section('title', 'Overtime')

@section('content')
@php
    use App\Support\Fmt;
    $ctx = app(\App\Support\CompanyContext::class);
    $reopen = old('_form') === 'overtime';
@endphp

<x-page-header title="Overtime" subtitle="Approved overtime is recorded here only; it is not turned into pay automatically."
    :breadcrumbs="[['Attendance'], ['Overtime']]" />

<div class="mb-4 flex gap-2">
    @foreach (['pending' => 'Waiting for review', 'history' => 'History'] as $key => $label)
        <a href="{{ route('admin.overtime', ['tab' => $key]) }}" @class(['rounded-full px-3.5 py-1.5 text-[13px] font-semibold transition', 'bg-primary text-white' => $tab === $key, 'bg-surface text-ink-2 ring-1 ring-line hover:text-ink' => $tab !== $key])>{{ $label }}</a>
    @endforeach
</div>

<x-data-table :empty="$requests->isEmpty()" emptyIcon="timer" :emptyTitle="$tab === 'pending' ? 'No overtime waiting for review' : 'No overtime history yet'" emptyText="Requests from the mobile app appear here.">
    <x-slot:head><th>Employee</th><th>Work date</th><th>Time</th><th class="text-right">Requested</th><th class="hidden text-right md:table-cell">Approved</th><th class="hidden lg:table-cell">Reason</th><th>{{ $tab === 'pending' ? 'Submitted' : 'Decision' }}</th>@if ($tab === 'pending')<th class="w-44"><span class="sr-only">Actions</span></th>@endif</x-slot:head>
    @foreach ($requests as $r)
        @php
            $canDecide = in_array($r->employee_id, $decidable, true);
            $base = ['action' => route('admin.overtime.decide', $r), 'note' => '', 'requested' => $r->requested_minutes, 'approved_minutes' => $r->requested_minutes];
        @endphp
        <tr>
            <td><span class="flex items-center gap-3"><x-avatar :name="$r->employee->full_name" :src="$r->employee->photo_path" size="sm" />
                <span><span class="block font-semibold text-ink">{{ $r->employee->full_name }}</span><span class="block text-xs text-ink-3">{{ $r->employee->employee_number }}</span></span></span></td>
            <td class="whitespace-nowrap">{{ $r->work_date->format('D, d M Y') }}</td>
            <td class="whitespace-nowrap tabular-nums">{{ Fmt::time($r->start_at) }}–{{ Fmt::time($r->end_at) }}</td>
            <td class="text-right"><x-duration :minutes="$r->requested_minutes" /></td>
            <td class="hidden text-right font-semibold text-ink md:table-cell"><x-duration :minutes="$r->approved_minutes" /></td>
            <td class="hidden max-w-72 lg:table-cell"><span class="line-clamp-2">{{ $r->reason }}</span></td>
            <td class="whitespace-nowrap text-xs">
                @if ($tab === 'pending')
                    {{ $ctx->toLocal($r->created_at)->format('d M, H:i') }}
                @else
                    <x-badge :status="$r->status" />
                    <span class="mt-1 block text-ink-3">{{ $r->reviewer?->name ?? '—' }}{{ $r->reviewed_at ? ' · '.$ctx->toLocal($r->reviewed_at)->format('d M, H:i') : '' }}</span>
                    @if ($r->review_note)<span class="block max-w-56 truncate text-ink-2" title="{{ $r->review_note }}">“{{ $r->review_note }}”</span>@endif
                @endif
            </td>
            @if ($tab === 'pending')
                <td class="whitespace-nowrap text-right">
                    @if ($canDecide)
                        <x-button variant="danger-soft" size="sm" :dispatch="['name' => 'overtime-form', 'data' => $base + ['decision' => 'reject', 'title' => 'Reject overtime for '.$r->employee->full_name]]">Reject</x-button>
                        <x-button size="sm" :dispatch="['name' => 'overtime-form', 'data' => $base + ['decision' => 'approve', 'title' => 'Approve overtime for '.$r->employee->full_name]]">Approve</x-button>
                    @else
                        <span class="text-xs text-ink-3">View only</span>
                    @endif
                </td>
            @endif
        </tr>
    @endforeach
    <x-slot:footer><x-pagination :paginator="$requests" /></x-slot:footer>
</x-data-table>

<x-modal name="overtime-form" title="Review overtime" :open="$reopen"
    :initial="['action' => old('_action'), 'decision' => old('decision', 'approve'), 'note' => old('note', ''), 'approved_minutes' => old('approved_minutes'), 'requested' => (int) old('_requested'), 'title' => 'Review overtime']">
    <form id="overtime-form" method="POST" :action="data.action" class="space-y-4">
        @csrf
        <input type="hidden" name="_form" value="overtime">
        <input type="hidden" name="_action" :value="data.action">
        <input type="hidden" name="_requested" :value="data.requested">
        <input type="hidden" name="decision" :value="data.decision">
        <div x-show="data.decision === 'approve'">
            <x-mfield name="approved_minutes" type="number" min="1" label="Approved minutes" x-bind:max="data.requested" helper="Defaults to the full request; you can approve less, never more." />
        </div>
        <p class="text-[13.5px] text-ink-2" x-show="data.decision === 'reject'">The employee sees your reason.</p>
        <x-mfield name="note" as="textarea" label="Note" :rows="3" helper="Required when rejecting." />
    </form>
    <x-slot:footer>
        <x-button variant="outline" @click="hide()">Cancel</x-button>
        <button type="submit" form="overtime-form" class="btn btn-md" :class="data.decision === 'reject' ? 'btn-danger' : 'btn-primary'" x-text="data.decision === 'reject' ? 'Reject' : 'Approve'"></button>
    </x-slot:footer>
</x-modal>
@endsection
