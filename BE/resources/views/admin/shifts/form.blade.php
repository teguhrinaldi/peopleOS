@extends('layouts.admin')
@php
    $editing = $shift !== null;
    $title = $editing ? 'Edit '.$shift->name : 'Add shift';
    $hm = fn ($t) => $t ? substr((string) $t, 0, 5) : '';
    $breaks = old('breaks', $editing
        ? $shift->breaks->sortBy('start_time')->map(fn ($b) => ['name' => $b->name, 'start_time' => $hm($b->start_time), 'end_time' => $hm($b->end_time), 'is_paid' => $b->is_paid])->values()->all()
        : [['name' => 'Lunch', 'start_time' => '12:00', 'end_time' => '13:00', 'is_paid' => false]]);
    $state = [
        'start' => old('start_time', $hm($shift?->start_time) ?: '08:00'),
        'end' => old('end_time', $hm($shift?->end_time) ?: '17:00'),
        'breaks' => array_values(array_map(fn ($b) => ['name' => $b['name'] ?? '', 'start_time' => $b['start_time'] ?? '', 'end_time' => $b['end_time'] ?? '', 'is_paid' => filter_var($b['is_paid'] ?? false, FILTER_VALIDATE_BOOLEAN)], $breaks)),
    ];
@endphp
@section('title', $title)

@section('content')
<x-page-header :title="$title" subtitle="Enter wall-clock times. Duration, the overnight flag and working time are calculated by the server."
    :breadcrumbs="[['Attendance'], ['Shifts', route('admin.shifts')], [$editing ? $shift->name : 'Add shift']]" />

@if ($editing)
    <x-alert tone="info" class="mb-6">Changes apply to days scheduled from now on. Attendance already recorded keeps the schedule it was captured with.</x-alert>
@endif

<form method="POST" action="{{ $editing ? route('admin.shifts.update', $shift) : route('admin.shifts.store') }}" novalidate class="space-y-6"
    x-data="shiftForm(@js($state))">
    @csrf
    @if ($editing) @method('PUT') @endif

    @if ($errors->any())
        <x-alert tone="danger" title="Please fix the highlighted fields.">{{ $errors->first('breaks') ?: 'Nothing was saved yet.' }}</x-alert>
    @endif

    <x-form-section title="Shift" description="A short code is shown on schedules and exports.">
        <x-input name="name" label="Name" :value="$shift?->name" placeholder="Regular Shift" required />
        <x-input name="code" label="Code" :value="$shift?->code" placeholder="REG" required />
        <div>
            <label for="f-start_time" class="mb-1.5 block text-[13px] font-medium text-ink">Starts <span class="text-danger">*</span></label>
            <input id="f-start_time" type="time" name="start_time" x-model="start" class="ctl" @error('start_time') aria-invalid="true" @enderror required>
            @error('start_time')<p class="mt-1.5 text-xs font-medium text-danger-ink">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="f-end_time" class="mb-1.5 block text-[13px] font-medium text-ink">Ends <span class="text-danger">*</span></label>
            <input id="f-end_time" type="time" name="end_time" x-model="end" class="ctl" @error('end_time') aria-invalid="true" @enderror required>
            @error('end_time')<p class="mt-1.5 text-xs font-medium text-danger-ink">{{ $message }}</p>
            @else<p class="mt-1.5 text-xs text-ink-3" x-show="overnight()">Ends the next day (overnight shift).</p>@enderror
        </div>
        <x-input name="grace_late_minutes" type="number" min="0" max="240" label="Late grace (minutes)" :value="$shift?->grace_late_minutes ?? 10" required helper="Check-ins within this window are on time." />
        <x-input name="grace_early_leave_minutes" type="number" min="0" max="240" label="Early-leave grace (minutes)" :value="$shift?->grace_early_leave_minutes ?? 0" required helper="Check-outs within this window are not early." />
        <x-toggle name="is_active" label="Active" helper="Inactive shifts cannot be assigned to anyone new." :checked="$shift?->is_active ?? true" class="sm:col-span-2" />
    </x-form-section>

    <x-form-section title="Breaks" description="Unpaid breaks are deducted from working time. Breaks must fall inside the shift and must not overlap.">
        <div class="space-y-3 sm:col-span-2">
            <template x-for="(row, i) in breaks" :key="i">
                <div class="grid grid-cols-2 items-end gap-3 rounded-xl border border-line bg-surface-2 p-3 sm:grid-cols-[1fr_120px_120px_auto_auto]">
                    <div class="col-span-2 sm:col-span-1">
                        <label class="mb-1 block text-xs font-medium text-ink-2" :for="'b-name-'+i">Name</label>
                        <input class="ctl bg-surface" :id="'b-name-'+i" :name="'breaks['+i+'][name]'" x-model="row.name" maxlength="60" placeholder="Lunch">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-ink-2" :for="'b-start-'+i">From</label>
                        <input type="time" class="ctl bg-surface" :id="'b-start-'+i" :name="'breaks['+i+'][start_time]'" x-model="row.start_time">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-ink-2" :for="'b-end-'+i">To</label>
                        <input type="time" class="ctl bg-surface" :id="'b-end-'+i" :name="'breaks['+i+'][end_time]'" x-model="row.end_time">
                    </div>
                    <label class="flex h-10 cursor-pointer items-center gap-2 text-[13px] text-ink-2">
                        <input type="hidden" :name="'breaks['+i+'][is_paid]'" :value="row.is_paid ? 1 : 0">
                        <input type="checkbox" x-model="row.is_paid" class="size-4 rounded accent-[var(--primary)]"> Paid
                    </label>
                    <button type="button" @click="breaks.splice(i, 1)" class="grid h-10 w-10 place-items-center rounded-lg text-ink-3 hover:bg-danger-soft hover:text-danger-ink" :aria-label="'Remove break '+(i+1)"><x-icon name="trash-2" class="size-4" /></button>
                </div>
            </template>
            @foreach ($errors->getMessages() as $key => $messages)
                @if (str_starts_with($key, 'breaks.'))
                    <p class="text-xs font-medium text-danger-ink">Break {{ (int) explode('.', $key)[1] + 1 }}: {{ $messages[0] }}</p>
                @endif
            @endforeach
            <x-button variant="outline" size="sm" icon="plus" @click="breaks.push({ name: '', start_time: '', end_time: '', is_paid: false })" x-bind:disabled="breaks.length >= 6">Add break</x-button>
        </div>
    </x-form-section>

    <x-card title="Preview" subtitle="Shown while you type; the server recalculates on save.">
        <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div><dt class="text-xs text-ink-3">Hours</dt><dd class="mt-1 text-[15px] font-semibold text-ink"><span x-text="start || '--:--'"></span> → <span x-text="end || '--:--'"></span> <span x-show="overnight()" class="rounded-md bg-info-soft px-1.5 py-px text-[11px] font-bold text-info-ink">+1</span></dd></div>
            <div><dt class="text-xs text-ink-3">Span</dt><dd class="mt-1 text-[15px] font-semibold text-ink" x-text="fmt(span())"></dd></div>
            <div><dt class="text-xs text-ink-3">Unpaid breaks</dt><dd class="mt-1 text-[15px] font-semibold text-ink" x-text="fmt(unpaid())"></dd></div>
            <div><dt class="text-xs text-ink-3">Working time</dt><dd class="mt-1 text-[15px] font-semibold text-primary-ink" x-text="fmt(Math.max(0, span() - unpaid()))"></dd></div>
        </dl>
    </x-card>

    <div class="sticky bottom-0 z-10 -mx-4 flex items-center justify-end gap-3 border-t border-line bg-surface/95 px-4 py-3 backdrop-blur sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8 no-print">
        <x-button variant="outline" :href="route('admin.shifts')">Cancel</x-button>
        <x-button type="submit">{{ $editing ? 'Save shift' : 'Create shift' }}</x-button>
    </div>
</form>
@endsection
