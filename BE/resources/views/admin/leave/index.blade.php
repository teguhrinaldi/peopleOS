@extends('layouts.admin')
@section('title', 'Leave requests')

@section('content')
@php
    use App\Support\DateDisplay;
    $isTeam = ! auth()->user()->hasAnyPermission('leave.view', 'leave.approve');
    $statuses = ['PENDING' => 'Pending', 'APPROVED' => 'Approved', 'REJECTED' => 'Rejected', 'CANCELLED' => 'Cancelled'];
    $active = collect($filters)->filter()->isNotEmpty();
    $reopen = old('_form') === 'leave-decision';
@endphp

<x-page-header title="Leave Requests" :subtitle="($isTeam ? 'Your team · ' : '').($tab === 'pending' ? 'Requests waiting for your decision. Your own requests are decided by someone else.' : 'Every submitted request you can see.')"
    :breadcrumbs="[['Leave'], ['Leave Requests']]" />

@include('admin.leave._tabs')

<x-filter-panel :action="route('admin.leave-requests')" :active="$active" :reset="route('admin.leave-requests', ['tab' => $tab])">
    <input type="hidden" name="tab" value="{{ $tab }}">
    @if (! empty($filters['employee_id']))<input type="hidden" name="employee_id" value="{{ $filters['employee_id'] }}">@endif
    @if ($tab !== 'pending')
        <x-select name="status" label="Status" :options="$statuses" :value="$filters['status'] ?? null" placeholder="Any" />
    @endif
    <x-select name="leave_type_id" label="Type" :options="$types" :value="$filters['leave_type_id'] ?? null" placeholder="All types" />
    <x-select name="department_id" label="Department" :options="$departments" :value="$filters['department_id'] ?? null" placeholder="All" />
    <x-input name="from" type="date" label="From" :value="$filters['from'] ?? null" />
    <x-input name="to" type="date" label="To" :value="$filters['to'] ?? null" />
</x-filter-panel>

<x-data-table :empty="$requests->isEmpty()" emptyIcon="calendar-days" class="mt-4 dt-sticky"
    :emptyTitle="$tab === 'pending' ? 'Nothing waiting for review' : 'No leave requests match'"
    :emptyText="$tab === 'pending' ? 'New requests from the mobile app appear here.' : 'Try another filter.'">
    <x-slot:head>
        <th>Employee</th><th>Type</th><th>Dates</th><th class="text-right">Days</th>
        <th class="hidden lg:table-cell">Reason</th>
        <th>{{ $tab === 'pending' ? 'Submitted' : 'Status' }}</th>
        <th class="w-40"><span class="sr-only">Actions</span></th>
    </x-slot:head>
    @foreach ($requests as $r)
        @php
            $panel = route('admin.leave-requests.show', [$r, 'panel' => 1]);
            $base = ['action' => route('admin.leave-requests.decide', $r), 'note' => ''];
        @endphp
        <tr>
            <td>
                <button type="button" class="group flex items-center gap-3 text-left" @click="$dispatch('open-leave', { url: @js($panel), title: @js($r->employee->full_name) })">
                    <x-avatar :name="$r->employee->full_name" :src="$r->employee->photo_path" size="sm" />
                    <span class="min-w-0"><span class="block truncate font-semibold text-ink group-hover:text-primary-ink">{{ $r->employee->full_name }}</span>
                        <span class="block text-xs text-ink-3">{{ $r->employee->employee_number }}</span></span>
                </button>
            </td>
            <td><x-leave-type :type="$r->leaveType" /></td>
            <td class="whitespace-nowrap">{{ DateDisplay::range($r->start_date, $r->end_date) }}@if ($r->half_day) <span class="text-xs text-ink-3">({{ $r->half_day }})</span>@endif</td>
            <td class="text-right font-semibold tabular-nums text-ink">{{ rtrim(rtrim((string) $r->days, '0'), '.') }}</td>
            <td class="hidden max-w-64 lg:table-cell"><span class="line-clamp-2 text-xs">{{ $r->reason ?? '—' }}</span></td>
            <td class="whitespace-nowrap text-xs">
                @if ($tab === 'pending')
                    {{ $r->submitted_at ? app(\App\Support\CompanyContext::class)->toLocal($r->submitted_at)->format('d M, H:i') : '—' }}
                @else
                    <x-badge :status="$r->status" />
                    @if ($r->reviewer)<span class="mt-1 block text-ink-3">{{ $r->reviewer->name }}</span>@endif
                @endif
            </td>
            <td class="whitespace-nowrap text-right">
                @if ($tab === 'pending')
                    <x-button variant="danger-soft" size="sm" :dispatch="['name' => 'leave-decision', 'data' => $base + ['decision' => 'reject', 'title' => 'Reject leave for '.$r->employee->full_name]]">Reject</x-button>
                    <x-button size="sm" :dispatch="['name' => 'leave-decision', 'data' => $base + ['decision' => 'approve', 'title' => 'Approve leave for '.$r->employee->full_name]]">Approve</x-button>
                @else
                    <button type="button" class="btn btn-sm btn-text" @click="$dispatch('open-leave', { url: @js($panel), title: @js($r->employee->full_name) })">Details <x-icon name="chevron-right" class="size-4" /></button>
                @endif
            </td>
        </tr>
    @endforeach
    <x-slot:footer><x-pagination :paginator="$requests" /></x-slot:footer>
</x-data-table>

@include('admin.leave._drawer')
@include('admin.leave._decision-modal', ['reopen' => $reopen])
@endsection
