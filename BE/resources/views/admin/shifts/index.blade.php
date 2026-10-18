@extends('layouts.admin')
@section('title', 'Shifts')

@section('content')
<x-page-header title="Shifts" subtitle="Working hours, breaks and grace rules. Overnight shifts are one span that ends the next day."
    :breadcrumbs="[['Attendance'], ['Shifts']]">
    <x-button :href="route('admin.shifts.create')" icon="plus">Add shift</x-button>
</x-page-header>

@include('admin.shifts._tabs', ['active' => 'shifts'])

<x-data-table :empty="$shifts->isEmpty()" emptyIcon="calendar-clock" emptyTitle="No shifts yet" emptyText="Create your first shift to schedule attendance.">
    <x-slot:head>
        <th>Shift</th><th>Hours</th><th class="hidden lg:table-cell">Breaks</th><th>Working time</th><th class="hidden md:table-cell">Grace (late / early)</th><th class="text-right">Employees</th><th>Status</th>
        <th class="w-24"><span class="sr-only">Actions</span></th>
    </x-slot:head>
    @foreach ($shifts as $shift)
        @php
            $count = $usage[$shift->id] ?? 0;
            $del = ['title' => 'Delete '.$shift->name.'?', 'text' => $count ? $shift->name.' is used by '.$count.' '.\Illuminate\Support\Str::plural('employee', $count).'. Reassign them first, or deactivate the shift instead.' : 'The shift will be removed. Attendance already recorded keeps its schedule.', 'action' => route('admin.shifts.destroy', $shift), 'method' => 'DELETE', 'label' => 'Delete shift'];
        @endphp
        <tr>
            <td><a href="{{ route('admin.shifts.edit', $shift) }}" class="block font-semibold text-ink hover:text-primary-ink">{{ $shift->name }}</a><span class="block text-xs text-ink-3">{{ $shift->code }}</span></td>
            <td><x-shift-time :start="$shift->start_time" :end="$shift->end_time" :overnight="$shift->crosses_midnight" /></td>
            <td class="hidden lg:table-cell">
                @forelse ($shift->breaks->sortBy('start_time') as $break)
                    <span class="block whitespace-nowrap text-xs">{{ $break->name }} · {{ substr($break->start_time, 0, 5) }}–{{ substr($break->end_time, 0, 5) }} <span class="text-ink-3">({{ $break->duration_minutes }}m{{ $break->is_paid ? ', paid' : '' }})</span></span>
                @empty
                    <span class="text-ink-3">None</span>
                @endforelse
            </td>
            <td class="font-semibold text-ink"><x-duration :minutes="$shift->working_minutes" /></td>
            <td class="hidden whitespace-nowrap tabular-nums md:table-cell">{{ $shift->grace_late_minutes }}m / {{ $shift->grace_early_leave_minutes }}m</td>
            <td class="text-right font-semibold tabular-nums text-ink">{{ $count }}</td>
            <td><x-badge :status="$shift->is_active ? 'active' : 'inactive'" /></td>
            <td class="whitespace-nowrap text-right">
                <x-button variant="text" size="sm" icon="pencil" :href="route('admin.shifts.edit', $shift)" :aria-label="'Edit '.$shift->name" />
                <x-button variant="text" size="sm" icon="trash-2" class="text-danger-ink" :dispatch="['name' => 'confirm', 'data' => $del]" :aria-label="'Delete '.$shift->name" />
            </td>
        </tr>
    @endforeach
</x-data-table>
<p class="mt-3 text-xs text-ink-3">Editing a shift only changes days that are scheduled from now on. Attendance already recorded keeps the schedule it was captured with.</p>
@endsection
