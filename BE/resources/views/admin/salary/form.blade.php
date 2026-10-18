@extends('layouts.admin')
@section('title', $structure ? 'Edit '.$structure->name : 'New salary structure')

@section('content')
@php
    $oldLines = old('components');
    $startLines = is_array($oldLines) ? array_values($oldLines) : $lines;
    $action = $structure ? route('admin.salary-structures.update', $structure) : route('admin.salary-structures.store');
@endphp
<x-page-header :title="$structure ? 'Edit '.$structure->name : 'New salary structure'" subtitle="A template of components and default values. Employees keep their own dated values after assignment."
    :breadcrumbs="[['Payroll'], ['Salary Structures', route('admin.salary-structures')], [$structure ? $structure->name : 'New']]" />

<form method="POST" action="{{ $action }}" class="max-w-4xl space-y-6"
    x-data="structureForm(@js($choices), @js($startLines))">
    @csrf
    @if ($structure) @method('PUT') @endif
    <x-card title="Details">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-input name="code" label="Code" :value="$structure?->code" placeholder="e.g. STAFF" maxlength="40" required />
            <x-input name="name" label="Name" :value="$structure?->name" maxlength="100" required />
            <x-input name="description" label="Description" :value="$structure?->description" maxlength="255" class="sm:col-span-2" />
            <x-toggle name="is_active" label="Active" helper="Inactive structures cannot be assigned." :checked="$structure ? $structure->is_active : true" class="sm:col-span-2" />
        </div>
    </x-card>

    <x-card title="Components" subtitle="Values here are defaults copied to an employee when the structure is assigned.">
        <p x-show="rows.length === 0" class="rounded-lg bg-surface-2 px-3 py-4 text-center text-[13px] text-ink-3">No components yet. Add the earnings and deductions this structure contains.</p>
        <div class="space-y-3">
            <template x-for="(row, i) in rows" :key="row.key">
                <div class="grid grid-cols-1 items-end gap-3 rounded-xl border border-line p-3 sm:grid-cols-[1fr_180px_auto]">
                    <div>
                        <label class="mb-1.5 block text-[13px] font-medium text-ink" :for="'c-' + i">Component</label>
                        <x-listbox><select :id="'c-' + i" :name="'components[' + i + '][salary_component_id]'" x-model="row.id" @change="pick(row)" class="ctl">
                            <option value="">Choose a component</option>
                            <template x-for="c in choices" :key="c.id"><option :value="c.id" x-text="c.label + ' · ' + c.kind.toLowerCase()" :selected="String(c.id) === String(row.id)"></option></template>
                        </select></x-listbox>
                        <p class="mt-1 text-xs text-ink-3" x-text="meta(row)?.calc"></p>
                    </div>
                    <div>
                        <template x-if="unit(row) === 'amount'">
                            <div><label class="mb-1.5 block text-[13px] font-medium text-ink" :for="'a-' + i">Amount</label>
                                <input :id="'a-' + i" type="number" step="any" min="0" class="ctl" :name="'components[' + i + '][amount]'" x-model="row.amount"></div>
                        </template>
                        <template x-if="unit(row) === 'rate'">
                            <div><label class="mb-1.5 block text-[13px] font-medium text-ink" :for="'r-' + i">Percentage</label>
                                <input :id="'r-' + i" type="number" step="any" min="0" max="1000" class="ctl" :name="'components[' + i + '][rate]'" x-model="row.rate"></div>
                        </template>
                    </div>
                    <button type="button" class="btn btn-md btn-text text-danger-ink" @click="remove(i)" :aria-label="'Remove component ' + (i + 1)"><x-icon name="trash-2" class="size-4" />Remove</button>
                </div>
            </template>
        </div>
        @error('components')<p class="mt-2 text-xs font-medium text-danger-ink">{{ $message }}</p>@enderror
        @foreach ($errors->get('components.*') as $key => $messages)<p class="mt-2 text-xs font-medium text-danger-ink">{{ $messages[0] }}</p>@endforeach
        <div class="mt-4"><x-button variant="outline" icon="plus" @click="add()">Add component</x-button></div>
    </x-card>

    <div class="flex items-center justify-end gap-3">
        <x-button variant="outline" :href="$structure ? route('admin.salary-structures.show', $structure) : route('admin.salary-structures')">Cancel</x-button>
        <x-button type="submit">{{ $structure ? 'Save structure' : 'Create structure' }}</x-button>
    </div>
</form>

@push('modals')
<script>
    document.addEventListener('alpine:init', () => {
        window.Alpine.data('structureForm', (choices, lines) => ({
            choices,
            seq: 0,
            rows: [],
            init() { lines.forEach((l) => this.rows.push(this.make(l))); },
            make(l = {}) { return { key: ++this.seq, id: l.salary_component_id ?? '', amount: l.amount ?? '', rate: l.rate ?? '' }; },
            meta(row) { return this.choices.find((c) => String(c.id) === String(row.id)); },
            unit(row) { return this.meta(row)?.unit ?? 'none'; },
            pick(row) { const m = this.meta(row); if (m) { row.amount = m.amount ?? ''; row.rate = m.rate ?? ''; } },
            add() { this.rows.push(this.make()); },
            remove(i) { this.rows.splice(i, 1); },
        }));
    });
</script>
@endpush
@endsection
