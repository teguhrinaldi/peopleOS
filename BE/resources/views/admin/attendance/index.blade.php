@extends('layouts.admin')
@section('title', 'Attendance')

@section('content')
@php
    use App\Enums\AttendanceEventType;
    use App\Support\Fmt;
    $single = $window['from'] !== null && $window['from'] === $window['to'];
    $rangeLabel = match (true) {
        $window['from'] === null && $window['to'] === null => 'All dates',
        $single => \Carbon\Carbon::parse($window['from'])->format('l, d M Y'),
        default => \Carbon\Carbon::parse($window['from'] ?? $window['to'])->format('d M Y').' – '.($window['to'] ? \Carbon\Carbon::parse($window['to'])->format('d M Y') : 'today'),
    };
    $isTeam = ! auth()->user()->hasPermission('attendance.view');
    $active = ! empty($filters['review']) ? 'review' : 'board';
    $exportUrl = route('admin.attendance.export', request()->query());
    $statusTone = fn ($a) => match (true) {
        $a->needs_review => ['pending', 'Needs review'],
        default => [strtolower($a->status->value), null],
    };
@endphp

<x-page-header title="Attendance" :subtitle="($isTeam ? 'Your team · ' : '').$rangeLabel" :breadcrumbs="[['Attendance'], ['Attendance']]">
    <x-button variant="outline" icon="download" :href="$exportUrl">Export CSV</x-button>
</x-page-header>

@include('admin.attendance._tabs', ['active' => $active])

<x-filter-panel :action="route('admin.attendance')" :active="collect($filters)->except(['from', 'to'])->filter()->isNotEmpty()" :reset="route('admin.attendance')" class="mb-4">
    @if (! empty($filters['employee_id']))<input type="hidden" name="employee_id" value="{{ $filters['employee_id'] }}">@endif
    <x-input name="from" type="date" label="From" :value="$window['from']" />
    <x-input name="to" type="date" label="To" :value="$window['to']" />
    <x-select name="department_id" label="Department" :options="$departments" :value="$filters['department_id'] ?? null" placeholder="All" />
    <x-select name="work_location_id" label="Location" :options="$locations" :value="$filters['work_location_id'] ?? null" placeholder="All" />
    <x-select name="status" label="Status" :options="$statuses" :value="$filters['status'] ?? null" placeholder="Any" />
    <div class="flex items-end pb-2.5">
        <label class="flex cursor-pointer items-center gap-2 text-[13px] font-medium text-ink">
            <input type="checkbox" name="review" value="1" class="size-4 rounded accent-[var(--primary)]" @checked(! empty($filters['review']))> Needs review only
        </label>
    </div>
</x-filter-panel>

@if (! empty($filters['employee_id']) && $records->isNotEmpty())
    <x-alert tone="info" class="mb-4">Showing one employee: {{ $records->first()->employee?->full_name }}. <a class="font-semibold underline" href="{{ route('admin.attendance', \Illuminate\Support\Arr::except(request()->query(), ['employee_id', 'page'])) }}">Show everyone</a></x-alert>
@endif

<section class="mb-4 grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-5" aria-label="Summary">
    <x-stat-card label="Records" :value="$summary['records']" icon="clipboard-list" tone="primary" />
    <x-stat-card label="Late" :value="$summary['late']" icon="clock" tone="warning" />
    <x-stat-card label="Left early" :value="$summary['early']" icon="log-out" tone="warning" />
    <x-stat-card label="Needs review" :value="$summary['review']" icon="flag" tone="danger" />
    @if ($missing !== null)
        <x-stat-card label="Not checked in" :value="$missing->count()" icon="user-minus" tone="danger" hint="No check-in yet" />
    @else
        <x-stat-card label="Hours worked" :value="round($summary['worked_minutes'] / 60, 1)" icon="timer" tone="success" />
    @endif
</section>

<x-data-table :empty="$records->isEmpty()" emptyIcon="clock" class="dt-sticky" emptyTitle="No attendance for these filters" emptyText="Try another date range or clear the filters.">
    <x-slot:head>
        <th>Employee</th>@unless ($single)<th>Date</th>@endunless<th class="hidden lg:table-cell">Shift</th><th>Check in</th><th>Check out</th><th class="hidden md:table-cell">Worked</th><th class="hidden md:table-cell">Location</th><th>Status</th><th class="w-10"><span class="sr-only">Open</span></th>
    </x-slot:head>
    @foreach ($records as $a)
        @php
            $in = $a->eventOfType(AttendanceEventType::CheckIn);
            $offline = $a->events->contains('is_offline_sync', true);
            [$st, $stLabel] = $statusTone($a);
        @endphp
        <tr>
            <td>
                <a href="{{ route('admin.attendance.show', $a) }}" class="group flex items-center gap-3">
                    <x-avatar :name="$a->employee?->full_name ?? '?'" :src="$a->employee?->photo_path" size="sm" />
                    <span class="min-w-0">
                        <span class="block truncate font-semibold text-ink group-hover:text-primary-ink">{{ $a->employee?->full_name }}</span>
                        <span class="block text-xs text-ink-3">{{ $a->employee?->employee_number }}@if ($a->department_name) · {{ $a->department_name }}@endif</span>
                    </span>
                </a>
            </td>
            @unless ($single)<td class="whitespace-nowrap">{{ $a->work_date->format('D, d M') }}</td>@endunless
            <td class="hidden whitespace-nowrap lg:table-cell">
                <span class="block text-ink">{{ $a->schedule?->shift_name ?? '—' }}</span>
                @if ($a->schedule?->expected_start_at)<span class="text-xs text-ink-3">{{ Fmt::time($a->schedule->expected_start_at) }}–{{ Fmt::time($a->schedule->expected_end_at) }}</span>@endif
            </td>
            <td class="whitespace-nowrap">
                <span class="font-semibold tabular-nums text-ink">{{ Fmt::time($a->check_in_at) ?? '—' }}</span>
                @if ($a->is_late)<x-badge status="late" :dot="false" class="ml-1">+{{ $a->late_minutes }}m</x-badge>@endif
            </td>
            <td class="whitespace-nowrap">
                <span class="font-semibold tabular-nums text-ink">{{ Fmt::time($a->check_out_at) ?? '—' }}</span>
                @if ($a->is_early_leave)<x-badge tone="warning" :dot="false" class="ml-1">−{{ $a->early_leave_minutes }}m</x-badge>@endif
            </td>
            <td class="hidden md:table-cell"><x-duration :minutes="$a->worked_minutes" /></td>
            <td class="hidden whitespace-nowrap md:table-cell">
                <span class="block text-ink">{{ $a->schedule?->location_name ?? '—' }}</span>
                <span class="flex items-center gap-1.5 text-xs">
                    @if ($in?->distance_meters !== null)
                        <span @class(['font-semibold', 'text-success-ink' => $in->inside_geofence !== false, 'text-danger-ink' => $in->inside_geofence === false])>{{ number_format($in->distance_meters) }} m</span>
                        <span class="text-ink-3">{{ $in->inside_geofence === false ? 'outside' : 'inside' }}</span>
                    @endif
                    @if ($offline)<x-badge tone="info" :dot="false" class="!px-1.5 !py-0 !text-[11px]">Offline</x-badge>@endif
                </span>
            </td>
            <td class="whitespace-nowrap">
                <x-badge :status="$st">{{ $stLabel ?? '' }}</x-badge>
                @if ($a->needs_review)<x-icon name="flag" class="ml-1 inline size-3.5 text-danger" aria-label="Needs review" />@endif
            </td>
            <td class="text-right"><a href="{{ route('admin.attendance.show', $a) }}" class="grid size-8 place-items-center rounded-lg text-ink-3 hover:bg-surface-3 hover:text-ink" aria-label="Open record"><x-icon name="chevron-right" class="size-4" /></a></td>
        </tr>
    @endforeach
    <x-slot:footer><x-pagination :paginator="$records" /></x-slot:footer>
</x-data-table>

@if ($missing !== null && $missing->isNotEmpty())
    <x-card title="Not checked in" :subtitle="$missing->count().' expected '.\Illuminate\Support\Str::plural('employee', $missing->count()).' have no check-in for this day yet.'" class="mt-6">
        <ul class="flex flex-wrap gap-2">
            @foreach ($missing as $e)
                <li class="flex items-center gap-2 rounded-full bg-surface-2 py-1 pl-1 pr-3 text-[13px] ring-1 ring-line">
                    <x-avatar :name="$e->full_name" :src="$e->photo_path" size="xs" /><span class="font-medium text-ink">{{ $e->full_name }}</span><span class="text-xs text-ink-3">{{ $e->employee_number }}</span>
                </li>
            @endforeach
        </ul>
    </x-card>
@endif
@endsection
