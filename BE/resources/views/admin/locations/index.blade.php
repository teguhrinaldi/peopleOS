@extends('layouts.admin')
@section('title', 'Work locations')

@section('content')
<x-page-header title="Work Locations" subtitle="Geofences used to validate check-ins. Coordinates are captured only at check-in and check-out."
    :breadcrumbs="[['Attendance'], ['Work Locations']]">
    <x-button :href="route('admin.work-locations.create')" icon="plus">Add location</x-button>
</x-page-header>

@include('admin.locations._tabs', ['active' => 'locations'])

<x-filter-bar :action="route('admin.work-locations')" :reset="route('admin.work-locations')" :q="$q" placeholder="Search by name or code" :active="$q !== ''" class="mb-4" />

<x-data-table :empty="$locations->isEmpty()" emptyIcon="map-pin" emptyTitle="No work locations" emptyText="Add an office or site so employees can check in there.">
    <x-slot:head><th>Location</th><th class="hidden lg:table-cell">Branch</th><th class="hidden xl:table-cell">Coordinates</th><th class="text-right">Radius</th><th class="hidden md:table-cell">Policy</th><th class="text-right">Employees</th><th>Status</th><th class="w-24"><span class="sr-only">Actions</span></th></x-slot:head>
    @foreach ($locations as $l)
        @php
            $count = $usage[$l->id] ?? 0;
            $del = ['title' => 'Delete '.$l->name.'?', 'text' => $count ? $l->name.' is assigned to '.$count.' '.\Illuminate\Support\Str::plural('employee', $count).'. Reassign them first, or deactivate it instead.' : 'The location will be removed. Attendance already recorded keeps its geofence.', 'action' => route('admin.work-locations.destroy', $l), 'method' => 'DELETE', 'label' => 'Delete location'];
            $policy = $l->attendancePolicy;
        @endphp
        <tr>
            <td><a href="{{ route('admin.work-locations.edit', $l) }}" class="block font-semibold text-ink hover:text-primary-ink">{{ $l->name }}</a><span class="block max-w-72 truncate text-xs text-ink-3">{{ $l->code }}@if ($l->address) · {{ $l->address }}@endif</span></td>
            <td class="hidden whitespace-nowrap lg:table-cell">{{ $l->branch?->name ?? '—' }}</td>
            <td class="hidden whitespace-nowrap font-mono text-xs xl:table-cell">{{ number_format($l->latitude, 5) }}, {{ number_format($l->longitude, 5) }}</td>
            <td class="text-right tabular-nums">{{ number_format($l->radius_meters) }} m</td>
            <td class="hidden whitespace-nowrap md:table-cell">
                @if ($policy)
                    <span class="block text-ink">{{ $policy->name }}</span>
                    <span class="text-xs text-ink-3">{{ ucfirst($policy->geofence_mode->value) }}{{ $policy->require_selfie ? ' · selfie' : '' }}</span>
                @else
                    <span class="text-ink-3">Default{{ $defaultPolicy ? ' ('.$defaultPolicy.')' : '' }}</span>
                @endif
            </td>
            <td class="text-right font-semibold tabular-nums text-ink">{{ $count }}</td>
            <td><x-badge :status="$l->is_active ? 'active' : 'inactive'" /></td>
            <td class="whitespace-nowrap text-right">
                <x-button variant="text" size="sm" icon="pencil" :href="route('admin.work-locations.edit', $l)" :aria-label="'Edit '.$l->name" />
                <x-button variant="text" size="sm" icon="trash-2" class="text-danger-ink" :dispatch="['name' => 'confirm', 'data' => $del]" :aria-label="'Delete '.$l->name" />
            </td>
        </tr>
    @endforeach
    <x-slot:footer><x-pagination :paginator="$locations" /></x-slot:footer>
</x-data-table>
@endsection
