@extends('layouts.admin')
@section('title', 'Shift overrides')

@section('content')
@php
    $reopen = old('_form') === 'override';
    $initial = [
        'employee_id' => (string) old('employee_id', ''), 'shift_id' => (string) old('shift_id', ''),
        'start_date' => old('start_date', $today), 'end_date' => old('end_date', ''), 'note' => old('note', ''),
    ];
    $states = ['current' => 'Running now', 'upcoming' => 'Upcoming', 'past' => 'Ended'];
@endphp

<x-page-header title="Shifts" subtitle="Date-ranged overrides replace an employee's usual shift for a period (e.g. a rota change)."
    :breadcrumbs="[['Attendance'], ['Shifts', route('admin.shifts')], ['Overrides']]">
    <x-button icon="plus" :dispatch="['name' => 'override-form', 'data' => $initial]">Add override</x-button>
</x-page-header>

@include('admin.shifts._tabs', ['active' => 'assignments'])

<div class="mb-4 flex flex-wrap gap-2">
    @foreach ($states as $key => $label)
        <a href="{{ route('admin.shifts.assignments', ['state' => $key]) }}" @class(['rounded-full px-3.5 py-1.5 text-[13px] font-semibold transition', 'bg-primary text-white' => $state === $key, 'bg-surface text-ink-2 ring-1 ring-line hover:text-ink' => $state !== $key])>{{ $label }}</a>
    @endforeach
</div>

<x-data-table :empty="$assignments->isEmpty()" emptyIcon="git-branch" :emptyTitle="'No '.strtolower($states[$state]).' overrides'" emptyText="Employees follow the shift in their placement unless an override says otherwise.">
    <x-slot:head><th>Employee</th><th>Shift</th><th>From</th><th>Until</th><th class="hidden md:table-cell">Note</th><th class="w-28"><span class="sr-only">Actions</span></th></x-slot:head>
    @foreach ($assignments as $a)
        @php
            $future = $a->start_date->toDateString() > $today;
            $end = ['title' => $future ? 'Remove this override?' : 'End this override today?', 'text' => $future ? 'It has not started yet, so it is removed completely.' : 'Days until today keep this shift; from tomorrow the usual shift applies again.', 'action' => route('admin.shifts.assignments.destroy', $a), 'method' => 'DELETE', 'label' => $future ? 'Remove' : 'End today'];
        @endphp
        <tr>
            <td>
                <span class="flex items-center gap-3"><x-avatar :name="$a->employee->full_name" :src="$a->employee->photo_path" size="sm" />
                    <span><span class="block font-semibold text-ink">{{ $a->employee->full_name }}</span><span class="block text-xs text-ink-3">{{ $a->employee->employee_number }}</span></span></span>
            </td>
            <td><span class="block font-medium text-ink">{{ $a->shift->name }}</span><x-shift-time :start="$a->shift->start_time" :end="$a->shift->end_time" class="text-xs" /></td>
            <td class="whitespace-nowrap">{{ $a->start_date->format('d M Y') }}</td>
            <td class="whitespace-nowrap">{{ $a->end_date?->format('d M Y') ?? 'Until further notice' }}</td>
            <td class="hidden max-w-64 truncate md:table-cell">{{ $a->note ?? '—' }}</td>
            <td class="text-right">
                @if ($state !== 'past')
                    <x-button variant="text" size="sm" :class="$future ? 'text-danger-ink' : ''" :dispatch="['name' => 'confirm', 'data' => $end]">{{ $future ? 'Remove' : 'End today' }}</x-button>
                @endif
            </td>
        </tr>
    @endforeach
    <x-slot:footer><x-pagination :paginator="$assignments" /></x-slot:footer>
</x-data-table>

<x-modal name="override-form" title="Add shift override" :open="$reopen" :initial="$initial">
    <form id="override-form" method="POST" action="{{ route('admin.shifts.assignments.store') }}" class="space-y-4">
        @csrf
        <input type="hidden" name="_form" value="override">
        <x-mfield name="employee_id" as="select" label="Employee" :options="$employees" placeholder="Select employee" required />
        <x-mfield name="shift_id" as="select" label="Shift" :options="$shifts" placeholder="Select shift" required />
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-mfield name="start_date" type="date" label="From" required :min="$today" />
            <x-mfield name="end_date" type="date" label="Until" helper="Leave empty for no end date." />
        </div>
        <x-mfield name="note" label="Note" placeholder="e.g. Covering the night rota" maxlength="255" />
    </form>
    <x-slot:footer>
        <x-button variant="outline" @click="hide()">Cancel</x-button>
        <x-button type="submit" form="override-form">Save override</x-button>
    </x-slot:footer>
</x-modal>
@endsection
