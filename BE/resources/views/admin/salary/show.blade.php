@extends('layouts.admin')
@section('title', $structure->name)

@section('content')
@php
    use App\Services\Admin\PayrollDesk;
    use App\Support\DateDisplay;
    $num = fn ($v) => $v === null ? null : rtrim(rtrim((string) $v, '0'), '.');
    $assignOpen = old('_form') === 'assign';
    $today = app(\App\Support\CompanyContext::class)->today()->toDateString();
    $del = ['title' => 'Delete '.$structure->name.'?', 'text' => 'The template is removed. Employees who were assigned it keep their own salary values and history.', 'action' => route('admin.salary-structures.destroy', $structure), 'method' => 'DELETE', 'label' => 'Delete structure'];
@endphp
<x-page-header :title="$structure->name" :subtitle="$structure->code.($structure->description ? ' · '.$structure->description : '')" :breadcrumbs="[['Payroll'], ['Salary Structures', route('admin.salary-structures')], [$structure->name]]">
    <x-badge :status="$structure->is_active ? 'active' : 'inactive'" />
    @if ($canManage)
        @if ($structure->is_active && $structure->lines->isNotEmpty())<x-button icon="user-plus" :dispatch="['name' => 'assign']">Assign to employees</x-button>@endif
        <x-button variant="outline" icon="pencil" :href="route('admin.salary-structures.edit', $structure)">Edit</x-button>
        <x-button variant="danger-soft" icon="trash-2" :dispatch="['name' => 'confirm', 'data' => $del]">Delete</x-button>
    @endif
</x-page-header>

<x-alert tone="warning" icon="info" title="No tax or statutory rules are built in" class="mb-5">Structures contain only the components you define. Configure tax and statutory deductions for your jurisdiction as components.</x-alert>

<div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    <x-card title="Components" class="lg:col-span-2" :padded="false">
        @if ($structure->lines->isEmpty())
            <x-empty-state icon="list-checks" title="No components" text="Edit the structure to add components." />
        @else
            <div class="relative overflow-x-auto"><table class="dt">
                <thead><tr><th>Component</th><th>Kind</th><th class="hidden sm:table-cell">Calculation</th><th class="text-right">Default value</th></tr></thead>
                <tbody>
                @foreach ($structure->lines->sortBy('sort_order') as $l)
                    <tr>
                        <td><span class="font-medium text-ink">{{ $l->component->name }}</span>@if ($l->component->is_basic) <x-badge tone="info" :dot="false">Basic</x-badge>@endif<span class="block text-xs text-ink-3">{{ $l->component->code }}</span></td>
                        <td><x-badge :tone="$l->component->kind->value === 'EARNING' ? 'success' : 'warning'" :dot="false">{{ ucfirst(strtolower($l->component->kind->value)) }}</x-badge></td>
                        <td class="hidden sm:table-cell">{{ \App\Services\Admin\SalaryDesk::CALCULATIONS[$l->component->calculation->value] ?? '' }}</td>
                        <td class="text-right font-semibold tabular-nums">{{ $l->amount !== null ? PayrollDesk::plain((string) $l->amount) : ($l->rate !== null ? $num($l->rate).' %' : '—') }}</td>
                    </tr>
                @endforeach
                </tbody></table></div>
        @endif
    </x-card>
    <x-card title="Usage">
        <p class="text-3xl font-bold text-ink">{{ $assigned }}</p>
        <p class="text-[13px] text-ink-3">employee(s) currently have values copied from this structure.</p>
        <p class="mt-4 text-xs text-ink-3">Assigning a structure appends dated rows to each employee's salary history. Nothing already recorded is overwritten, and finalized payroll is never recalculated.</p>
    </x-card>
</div>

@if ($canManage && $structure->is_active && $structure->lines->isNotEmpty())
    <x-modal name="assign" size="lg" title="Assign structure to employees" description="Copies this structure into each selected employee's salary history from the date below." :open="$assignOpen">
        <form id="assign-form" method="POST" action="{{ route('admin.salary-structures.assign', $structure) }}" class="space-y-4"
            x-data="{ q: '', picked: @js(array_map('strval', (array) old('employee_ids', []))), ids: @js(array_map('strval', array_keys($employees))),
                shown(label) { return this.q === '' || label.toLowerCase().includes(this.q.toLowerCase()); },
                all() { this.picked = [...this.ids]; }, none() { this.picked = []; } }">
            @csrf
            <input type="hidden" name="_form" value="assign">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-input name="effective_from" type="date" label="Effective from" :value="$today" required />
                <x-input name="reason" label="Reason" placeholder="e.g. Annual review" maxlength="255" />
            </div>
            <div>
                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <label for="emp-q" class="text-[13px] font-medium text-ink">Employees <span class="text-danger">*</span> <span class="font-normal text-ink-3" x-text="'(' + picked.length + ' selected)'"></span></label>
                    <span class="flex gap-3 text-xs font-semibold text-primary-ink"><button type="button" @click="all()">Select all</button><button type="button" @click="none()">Clear</button></span>
                </div>
                <input id="emp-q" type="search" x-model="q" class="ctl mb-2" placeholder="Filter by name or number">
                <ul class="max-h-56 divide-y divide-line overflow-y-auto rounded-xl border border-line">
                    @foreach ($employees as $id => $label)
                        <li x-show="shown(@js($label))"><label class="flex cursor-pointer items-center gap-3 px-3 py-2 text-[13px] hover:bg-surface-2"><input type="checkbox" name="employee_ids[]" value="{{ $id }}" x-model="picked" class="size-4 accent-[var(--primary)]"><span>{{ $label }}</span></label></li>
                    @endforeach
                </ul>
                @error('employee_ids')<p class="mt-1.5 text-xs font-medium text-danger-ink">{{ $message }}</p>@enderror
            </div>
        </form>
        <x-slot:footer><x-button variant="outline" @click="hide()">Cancel</x-button><x-button type="submit" form="assign-form">Assign structure</x-button></x-slot:footer>
    </x-modal>
@endif
@endsection
