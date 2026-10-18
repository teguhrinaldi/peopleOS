@extends('layouts.admin')
@section('title', 'Holidays')

@section('content')
@php
    use App\Services\Admin\HolidayService;
    $blank = ['id' => null, 'action' => route('admin.shifts.holidays.store'), 'title' => 'New holiday', 'date' => '', 'name' => '', 'type' => 'national'];
    $reopen = old('_form') === 'holiday';
    $initial = $reopen ? ['id' => old('_id'), 'action' => old('_action'), 'title' => old('_id') ? 'Edit holiday' : 'New holiday', 'date' => old('date'), 'name' => old('name'), 'type' => old('type')] : $blank;
@endphp

<x-page-header title="Shifts" subtitle="Holidays are days off for everyone. Once a holiday is part of a recorded schedule it is kept as history."
    :breadcrumbs="[['Attendance'], ['Shifts', route('admin.shifts')], ['Holidays']]">
    <x-button icon="plus" :dispatch="['name' => 'holiday-form', 'data' => $blank]">Add holiday</x-button>
</x-page-header>

@include('admin.shifts._tabs', ['active' => 'holidays'])

<div class="mb-4 flex flex-wrap items-center gap-2" role="navigation" aria-label="Year">
    @foreach ($years as $y)
        <a href="{{ route('admin.shifts.holidays', ['year' => $y]) }}" @class(['rounded-full px-3.5 py-1.5 text-[13px] font-semibold tabular-nums transition', 'bg-primary text-white' => $y === $year, 'bg-surface text-ink-2 ring-1 ring-line hover:text-ink' => $y !== $year])>{{ $y }}</a>
    @endforeach
</div>

<x-data-table :empty="$holidays->isEmpty()" emptyIcon="flag" :emptyTitle="'No holidays in '.$year" emptyText="Add public or company holidays so nobody is expected to check in.">
    <x-slot:head><th>Date</th><th>Holiday</th><th>Type</th><th>Status</th><th class="w-24"><span class="sr-only">Actions</span></th></x-slot:head>
    @foreach ($holidays as $h)
        @php
            $isFrozen = in_array($h->id, $frozen, true);
            $edit = ['id' => $h->id, 'action' => route('admin.shifts.holidays.update', $h), 'title' => 'Edit holiday', 'date' => $h->date->toDateString(), 'name' => $h->name, 'type' => $h->type];
            $del = ['title' => 'Remove '.$h->name.'?', 'text' => 'The day becomes a normal working day for schedules created from now on.', 'action' => route('admin.shifts.holidays.destroy', $h), 'method' => 'DELETE', 'label' => 'Remove holiday'];
        @endphp
        <tr>
            <td class="whitespace-nowrap font-semibold text-ink">{{ $h->date->format('D, d M Y') }}</td>
            <td>{{ $h->name }}</td>
            <td>{{ HolidayService::TYPES[$h->type] ?? ucfirst($h->type) }}</td>
            <td>@if ($isFrozen)<x-badge tone="neutral" :dot="false">In recorded schedules</x-badge>@else<x-badge tone="info" :dot="false">Upcoming</x-badge>@endif</td>
            <td class="whitespace-nowrap text-right">
                <x-button variant="text" size="sm" icon="pencil" :dispatch="['name' => 'holiday-form', 'data' => $edit]" :aria-label="'Edit '.$h->name" />
                @unless ($isFrozen)
                    <x-button variant="text" size="sm" icon="trash-2" class="text-danger-ink" :dispatch="['name' => 'confirm', 'data' => $del]" :aria-label="'Remove '.$h->name" />
                @endunless
            </td>
        </tr>
    @endforeach
</x-data-table>

<x-modal name="holiday-form" :open="$reopen" :initial="$initial" title="Holiday">
    <form id="holiday-form" method="POST" :action="data.action" class="space-y-4">
        @csrf
        <input type="hidden" name="_form" value="holiday">
        <input type="hidden" name="_id" :value="data.id">
        <input type="hidden" name="_action" :value="data.action">
        <template x-if="data.id"><input type="hidden" name="_method" value="PUT"></template>
        <x-mfield name="date" type="date" label="Date" required />
        <x-mfield name="name" label="Name" required placeholder="Independence Day" maxlength="100" />
        <x-mfield name="type" as="select" label="Type" :options="HolidayService::TYPES" />
    </form>
    <x-slot:footer>
        <x-button variant="outline" @click="hide()">Cancel</x-button>
        <x-button type="submit" form="holiday-form">Save holiday</x-button>
    </x-slot:footer>
</x-modal>
@endsection
