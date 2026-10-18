@extends('layouts.admin')
@section('title', 'Working calendars')

@section('content')
@php
    use App\Services\Admin\CalendarService;
    $days = CalendarService::DAYS;
    $blank = ['id' => null, 'action' => route('admin.shifts.calendars.store'), 'title' => 'New working calendar', 'name' => '', 'description' => '', 'is_default' => false, 'days' => [1, 2, 3, 4, 5]];
    $reopen = old('_form') === 'calendar';
    $initial = $reopen ? [
        'id' => old('_id'), 'action' => old('_action'), 'title' => old('_id') ? 'Edit working calendar' : 'New working calendar',
        'name' => old('name'), 'description' => old('description'), 'is_default' => (bool) old('is_default'),
        'days' => collect((array) old('days', []))->filter(fn ($v) => filter_var($v, FILTER_VALIDATE_BOOLEAN))->keys()->map(fn ($k) => (int) $k)->values()->all(),
    ] : $blank;
    $assignReopen = old('_form') === 'calendar-assignment';
    $assignInitial = ['employee_id' => (string) old('employee_id', ''), 'working_calendar_id' => (string) old('working_calendar_id', ''), 'effective_from' => old('effective_from', $today), 'effective_to' => old('effective_to', '')];
@endphp

<x-page-header title="Shifts" subtitle="Which weekdays are worked. Employees without their own calendar follow the default."
    :breadcrumbs="[['Attendance'], ['Shifts', route('admin.shifts')], ['Working calendars']]">
    <x-button variant="outline" icon="user-plus" :dispatch="['name' => 'calendar-assignment', 'data' => $assignInitial]">Assign calendar</x-button>
    <x-button icon="plus" :dispatch="['name' => 'calendar-form', 'data' => $blank]">Add calendar</x-button>
</x-page-header>

@include('admin.shifts._tabs', ['active' => 'calendars'])

<div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
    @forelse ($calendars as $calendar)
        @php
            $worked = $calendar->days->where('is_working_day', true)->pluck('day_of_week')->map(fn ($d) => (int) $d)->values()->all();
            $edit = ['id' => $calendar->id, 'action' => route('admin.shifts.calendars.update', $calendar), 'title' => 'Edit working calendar', 'name' => $calendar->name, 'description' => (string) $calendar->description, 'is_default' => $calendar->is_default, 'days' => $worked];
            $del = ['title' => 'Delete '.$calendar->name.'?', 'text' => 'The calendar will be removed. Days already recorded keep their schedule.', 'action' => route('admin.shifts.calendars.destroy', $calendar), 'method' => 'DELETE', 'label' => 'Delete calendar'];
        @endphp
        <x-card>
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="flex items-center gap-2 text-[15px] font-semibold text-ink">{{ $calendar->name }} @if ($calendar->is_default)<x-badge tone="info" :dot="false">Default</x-badge>@endif</h2>
                    <p class="mt-0.5 text-[12.5px] text-ink-3">{{ $calendar->description ?: 'No description' }}</p>
                </div>
                <div class="flex shrink-0 gap-1">
                    <x-button variant="text" size="sm" icon="pencil" :dispatch="['name' => 'calendar-form', 'data' => $edit]" :aria-label="'Edit '.$calendar->name" />
                    @unless ($calendar->is_default)
                        <x-button variant="text" size="sm" icon="trash-2" class="text-danger-ink" :dispatch="['name' => 'confirm', 'data' => $del]" :aria-label="'Delete '.$calendar->name" />
                    @endunless
                </div>
            </div>
            <div class="mt-4 grid grid-cols-7 gap-1.5" role="list" aria-label="Working days">
                @foreach ($days as $iso => $label)
                    @php $on = in_array($iso, $worked, true); @endphp
                    <span role="listitem" @class(['rounded-lg py-2 text-center text-xs font-semibold', 'bg-primary text-white' => $on, 'bg-surface-3 text-ink-3' => ! $on])>
                        {{ $label }}<span class="sr-only">{{ $on ? ' worked' : ' off' }}</span>
                    </span>
                @endforeach
            </div>
        </x-card>
    @empty
        <x-card class="lg:col-span-2" :padded="false"><x-empty-state icon="calendar-days" title="No working calendars" text="Without a calendar, Monday to Friday are working days." /></x-card>
    @endforelse
</div>

<x-card title="Employee calendar assignments" subtitle="Running and upcoming. Past assignments are kept as history." class="mt-6" :padded="false">
    @if ($assignments->isEmpty())
        <p class="border-t border-line px-5 py-6 text-center text-[13px] text-ink-3">Everyone follows the default calendar.</p>
    @else
        <div class="relative overflow-x-auto border-t border-line">
            <table class="dt">
                <thead><tr><th>Employee</th><th>Calendar</th><th>From</th><th>Until</th><th class="w-28"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    @foreach ($assignments as $a)
                        @php
                            $future = $a->effective_from->toDateString() > $today;
                            $end = ['title' => $future ? 'Remove this assignment?' : 'End this assignment today?', 'text' => $future ? 'It has not started yet, so it is removed completely.' : 'From tomorrow the employee follows the default calendar again.', 'action' => route('admin.shifts.calendars.unassign', $a), 'method' => 'DELETE', 'label' => $future ? 'Remove' : 'End today'];
                        @endphp
                        <tr>
                            <td><span class="font-semibold text-ink">{{ $a->employee->full_name }}</span> <span class="text-xs text-ink-3">{{ $a->employee->employee_number }}</span></td>
                            <td>{{ $a->calendar->name }}</td>
                            <td class="whitespace-nowrap">{{ $a->effective_from->format('d M Y') }}</td>
                            <td class="whitespace-nowrap">{{ $a->effective_to?->format('d M Y') ?? 'Until further notice' }}</td>
                            <td class="text-right"><x-button variant="text" size="sm" :class="$future ? 'text-danger-ink' : ''" :dispatch="['name' => 'confirm', 'data' => $end]">{{ $future ? 'Remove' : 'End today' }}</x-button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-card>

<x-modal name="calendar-form" :open="$reopen" :initial="$initial" title="Working calendar">
    <form id="calendar-form" method="POST" :action="data.action" class="space-y-4">
        @csrf
        <input type="hidden" name="_form" value="calendar">
        <input type="hidden" name="_id" :value="data.id">
        <input type="hidden" name="_action" :value="data.action">
        <template x-if="data.id"><input type="hidden" name="_method" value="PUT"></template>
        <x-mfield name="name" label="Name" required placeholder="Standard week (Mon–Fri)" />
        <x-mfield name="description" as="textarea" label="Description" :rows="2" />
        <fieldset>
            <legend class="mb-1.5 block text-[13px] font-medium text-ink">Working days</legend>
            <div class="grid grid-cols-7 gap-1.5">
                @foreach ($days as $iso => $label)
                    <label class="cursor-pointer">
                        <input type="hidden" name="days[{{ $iso }}]" :value="(data.days || []).includes({{ $iso }}) ? 1 : 0">
                        <input type="checkbox" class="peer sr-only" :checked="(data.days || []).includes({{ $iso }})"
                            @change="data.days = $event.target.checked ? [...(data.days || []), {{ $iso }}] : (data.days || []).filter(d => d !== {{ $iso }})">
                        <span class="block rounded-lg bg-surface-3 py-2.5 text-center text-xs font-semibold text-ink-3 transition peer-checked:bg-primary peer-checked:text-white peer-focus-visible:outline-2 peer-focus-visible:outline-primary">{{ $label }}</span>
                    </label>
                @endforeach
            </div>
        </fieldset>
        <x-mfield name="is_default" as="toggle" label="Default" helper="Company default (used by everyone without their own calendar)" />
        @error('is_default')<p class="text-xs font-medium text-danger-ink">{{ $message }}</p>@enderror
    </form>
    <x-slot:footer>
        <x-button variant="outline" @click="hide()">Cancel</x-button>
        <x-button type="submit" form="calendar-form">Save calendar</x-button>
    </x-slot:footer>
</x-modal>

<x-modal name="calendar-assignment" title="Assign a working calendar" :open="$assignReopen" :initial="$assignInitial">
    <form id="calendar-assignment-form" method="POST" action="{{ route('admin.shifts.calendars.assign') }}" class="space-y-4">
        @csrf
        <input type="hidden" name="_form" value="calendar-assignment">
        <x-mfield name="employee_id" as="select" label="Employee" :options="$employees" placeholder="Select employee" required />
        <x-mfield name="working_calendar_id" as="select" label="Calendar" :options="$calendars->pluck('name', 'id')->all()" placeholder="Select calendar" required />
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-mfield name="effective_from" type="date" label="From" required :min="$today" />
            <x-mfield name="effective_to" type="date" label="Until" helper="Leave empty for no end date." />
        </div>
    </form>
    <x-slot:footer>
        <x-button variant="outline" @click="hide()">Cancel</x-button>
        <x-button type="submit" form="calendar-assignment-form">Save assignment</x-button>
    </x-slot:footer>
</x-modal>
@endsection
