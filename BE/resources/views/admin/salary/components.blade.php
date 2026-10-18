@extends('layouts.admin')
@section('title', 'Salary components')

@section('content')
@php
    use App\Services\Admin\PayrollDesk;
    $num = fn ($v) => $v === null ? null : rtrim(rtrim((string) $v, '0'), '.');
    $units = ['FIXED' => 'amount', 'PERCENT_OF_BASIC' => 'rate', 'PERCENT_OF_GROSS' => 'rate', 'PER_PRESENT_DAY' => 'amount', 'PER_OVERTIME_HOUR' => 'amount', 'UNPAID_LEAVE_DEDUCTION' => 'rate'];
    $blank = ['title' => 'Add salary component', 'action' => route('admin.salary-structures.components.store'), 'method' => 'POST', 'code' => '', 'name' => '', 'kind' => 'EARNING', 'calculation' => 'FIXED',
        'is_basic' => false, 'is_active' => true, 'default_amount' => '', 'default_rate' => '', 'description' => '', 'sort_order' => '0', 'locked' => false];
    $reopen = old('_form') === 'component';
    $initial = $reopen ? ['title' => old('_title', 'Salary component'), 'action' => old('_action'), 'method' => old('_method', 'POST'), 'code' => old('code'), 'name' => old('name'), 'kind' => old('kind'), 'calculation' => old('calculation'),
        'is_basic' => (bool) old('is_basic'), 'is_active' => (bool) old('is_active'), 'default_amount' => old('default_amount'), 'default_rate' => old('default_rate'), 'description' => old('description'), 'sort_order' => old('sort_order'), 'locked' => (bool) old('_locked')] : $blank;
@endphp
<x-page-header title="Salary Components" subtitle="The catalogue of earnings and deductions. Components used by employees or payroll cannot change meaning; deactivate them instead." :breadcrumbs="[['Payroll'], ['Salary Structures', route('admin.salary-structures')], ['Components']]">
    @if ($canManage)<x-button icon="plus" :dispatch="['name' => 'component', 'data' => $blank]">Add component</x-button>@endif
</x-page-header>

@include('admin.salary._header', ['tab' => 'components'])

<x-data-table :empty="$components->isEmpty()" emptyIcon="list-checks" emptyTitle="No components yet" emptyText="Start with a Basic Salary component (a fixed earning flagged as basic), then add allowances and deductions.">
    @if ($canManage)
        <x-slot:emptyAction><x-button icon="plus" :dispatch="['name' => 'component', 'data' => $blank]">Add the first component</x-button></x-slot:emptyAction>
    @endif
    <x-slot:head><th>Component</th><th>Kind</th><th class="hidden md:table-cell">Calculation</th><th class="text-right">Default</th><th>Status</th>@if ($canManage)<th class="w-24"><span class="sr-only">Actions</span></th>@endif</x-slot:head>
    @foreach ($components as $c)
        @php
            $default = $c->calculation->usesAmount() ? ($c->default_amount !== null ? PayrollDesk::plain((string) $c->default_amount) : null) : ($c->default_rate !== null ? $num($c->default_rate).' %' : null);
            $edit = ['title' => 'Edit '.$c->name, 'action' => route('admin.salary-structures.components.update', $c), 'method' => 'PUT', 'code' => $c->code, 'name' => $c->name, 'kind' => $c->kind->value, 'calculation' => $c->calculation->value,
                'is_basic' => $c->is_basic, 'is_active' => $c->is_active, 'default_amount' => $num($c->default_amount) ?? '', 'default_rate' => $num($c->default_rate) ?? '', 'description' => $c->description ?? '', 'sort_order' => (string) $c->sort_order, 'locked' => $c->locked];
        @endphp
        <tr>
            <td><span class="font-semibold text-ink">{{ $c->name }}</span>@if ($c->is_basic) <x-badge tone="info" :dot="false">Basic</x-badge>@endif<span class="block text-xs text-ink-3">{{ $c->code }}@if ($c->description) &middot; {{ $c->description }}@endif</span></td>
            <td><x-badge :tone="$c->kind->value === 'EARNING' ? 'success' : 'warning'" :dot="false">{{ ucfirst(strtolower($c->kind->value)) }}</x-badge></td>
            <td class="hidden md:table-cell">{{ \App\Services\Admin\SalaryDesk::CALCULATIONS[$c->calculation->value] ?? $c->calculation->value }}</td>
            <td class="text-right tabular-nums">{{ $default ?? '—' }}</td>
            <td><x-badge :status="$c->is_active ? 'active' : 'inactive'" /></td>
            @if ($canManage)
                <td class="text-right whitespace-nowrap">
                    <x-button variant="text" size="sm" icon="pencil" :dispatch="['name' => 'component', 'data' => $edit]">Edit</x-button>
                    @if ($c->deletable)
                        <x-button variant="text" size="sm" icon="trash-2" class="text-danger-ink" aria-label="Delete {{ $c->name }}" :dispatch="['name' => 'confirm', 'data' => ['title' => 'Delete '.$c->name.'?', 'text' => 'It has never been used, so it is removed completely.', 'action' => route('admin.salary-structures.components.destroy', $c), 'method' => 'DELETE', 'label' => 'Delete component']]" />
                    @endif
                </td>
            @endif
        </tr>
    @endforeach
</x-data-table>

@if ($canManage)
    <x-modal name="component" size="lg" title="Salary component" :initial="$initial" :open="$reopen">
        <form id="component-form" method="POST" :action="data.action" class="grid grid-cols-1 gap-4 sm:grid-cols-2" x-data="{ units: @js($units) }">
            @csrf
            <input type="hidden" name="_form" value="component">
            <input type="hidden" name="_method" :value="data.method">
            <input type="hidden" name="_action" :value="data.action">
            <input type="hidden" name="_title" :value="data.title">
            <input type="hidden" name="_locked" :value="data.locked ? 1 : 0">
            <p x-show="data.locked" x-cloak class="rounded-lg bg-info-soft px-3 py-2 text-xs text-info-ink sm:col-span-2">This component is used by employees or past payroll, so its kind, calculation and basic flag are locked. You can still rename it, change defaults or deactivate it.</p>
            <x-mfield name="code" label="Code" placeholder="e.g. MEAL" required maxlength="40" />
            <x-mfield name="name" label="Name" placeholder="e.g. Meal allowance" required maxlength="100" />
            <x-mfield name="kind" label="Kind" as="select" :options="$kinds" />
            <x-mfield name="calculation" label="Calculation" as="select" :options="$calculations" />
            <div x-show="units[data.calculation] === 'amount'">
                <x-mfield name="default_amount" label="Default amount" type="number" step="any" min="0" helper="Used when a structure or employee does not set its own." />
            </div>
            <div x-show="units[data.calculation] === 'rate'" x-cloak>
                <x-mfield name="default_rate" label="Default percentage" type="number" step="any" min="0" max="1000" helper="Blank for unpaid leave means 100% of the daily rate." />
            </div>
            <x-mfield name="sort_order" label="Order" type="number" min="0" max="9999" helper="Lower numbers appear first on payslips." />
            <x-mfield name="description" label="Description" maxlength="255" class="sm:col-span-2" />
            <x-mfield name="is_basic" label="Basic salary" as="toggle" helper="This is the employee's basic salary (one per company, fixed earning). Percent components use it as the base." />
            <x-mfield name="is_active" label="Active" as="toggle" helper="Inactive components cannot be added to new salaries." />
        </form>
        <x-slot:footer>
            <x-button variant="outline" @click="hide()">Cancel</x-button>
            <x-button type="submit" form="component-form">Save component</x-button>
        </x-slot:footer>
    </x-modal>
@endif
@endsection
