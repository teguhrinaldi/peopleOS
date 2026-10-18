@extends('layouts.admin')
@section('title', 'Departments')

@section('content')
@php
    $canManage = auth()->user()->hasPermission('organization.manage');
    $blank = ['id' => null, 'action' => route('admin.departments.store'), 'title' => 'New department', 'code' => '', 'name' => '', 'description' => '', 'branch_id' => '', 'parent_id' => '', 'is_active' => true];
    $reopen = old('_form') === 'department';
    $initial = $reopen ? [
        'id' => old('_id'), 'action' => old('_action'), 'title' => old('_id') ? 'Edit department' : 'New department',
        'code' => old('code'), 'name' => old('name'), 'description' => old('description'),
        'branch_id' => (string) old('branch_id'), 'parent_id' => (string) old('parent_id'), 'is_active' => (bool) old('is_active'),
    ] : $blank;
    $parentOptions = $parents->mapWithKeys(fn ($p) => [$p->id => $p->name.' ('.$p->code.')'])->all();
@endphp

<x-page-header title="Departments" subtitle="Teams and units, with the people currently placed in each.">
    @if ($canManage)<x-button icon="plus" :dispatch="['name' => 'department-form', 'data' => $blank]">Add department</x-button>@endif
</x-page-header>

<x-filter-bar :action="route('admin.departments.index')" :reset="route('admin.departments.index')" :q="$q" placeholder="Search by name or code" :active="$q !== '' || $state" class="mb-4">
    <x-select name="state" :options="['active' => 'Active only', 'inactive' => 'Inactive only']" :value="$state" placeholder="Any state" class="w-full sm:w-44" aria-label="State" />
</x-filter-bar>

<x-data-table :empty="$departments->isEmpty()" emptyIcon="building-2" emptyTitle="No departments found" emptyText="Create a department to start placing employees.">
    <x-slot:head>
        <th>Department</th><th class="hidden md:table-cell">Branch</th><th class="hidden lg:table-cell">Parent</th><th class="text-right">Employees</th><th>Status</th>
        @if ($canManage)<th class="w-24"><span class="sr-only">Actions</span></th>@endif
    </x-slot:head>
    @foreach ($departments as $d)
        @php
            $count = $headcount[$d->id] ?? 0;
            $edit = ['id' => $d->id, 'action' => route('admin.departments.update', $d), 'title' => 'Edit department', 'code' => $d->code, 'name' => $d->name,
                'description' => (string) $d->description, 'branch_id' => (string) $d->branch_id, 'parent_id' => (string) $d->parent_id, 'is_active' => $d->is_active];
            $del = ['title' => 'Delete '.$d->name.'?', 'text' => $count ? $d->name.' still has '.$count.' '.\Illuminate\Support\Str::plural('employee', $count).'. Reassign them first, or deactivate the department instead.' : 'The department will be removed. This is recorded in the audit trail.', 'action' => route('admin.departments.destroy', $d), 'method' => 'DELETE', 'label' => 'Delete department'];
        @endphp
        <tr>
            <td><span class="block font-semibold text-ink">{{ $d->name }}</span><span class="block text-xs text-ink-3">{{ $d->code }}</span></td>
            <td class="hidden md:table-cell">{{ $d->branch?->name ?? '—' }}</td>
            <td class="hidden lg:table-cell">{{ $d->parent?->name ?? '—' }}</td>
            <td class="text-right font-semibold tabular-nums text-ink">{{ $count }}</td>
            <td><x-badge :status="$d->is_active ? 'active' : 'inactive'" /></td>
            @if ($canManage)
                <td class="text-right whitespace-nowrap">
                    <x-button variant="text" size="sm" icon="pencil" :dispatch="['name' => 'department-form', 'data' => $edit]" :aria-label="'Edit '.$d->name" />
                    <x-button variant="text" size="sm" icon="trash-2" class="text-danger-ink" :dispatch="['name' => 'confirm', 'data' => $del]" :aria-label="'Delete '.$d->name" />
                </td>
            @endif
        </tr>
    @endforeach
    <x-slot:footer><x-pagination :paginator="$departments" /></x-slot:footer>
</x-data-table>

@if ($canManage)
    <x-modal name="department-form" :open="$reopen" :initial="$initial" title="Department">
        <form id="department-form" method="POST" :action="data.action" class="space-y-4">
            @csrf
            <input type="hidden" name="_form" value="department">
            <input type="hidden" name="_id" :value="data.id">
            <input type="hidden" name="_action" :value="data.action">
            <template x-if="data.id"><input type="hidden" name="_method" value="PUT"></template>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <x-mfield name="code" label="Code" required placeholder="ENG" class="sm:col-span-1" />
                <x-mfield name="name" label="Name" required placeholder="Engineering" class="sm:col-span-2" />
            </div>
            <x-mfield name="branch_id" as="select" label="Branch" :options="$branches" placeholder="No branch" />
            <x-mfield name="parent_id" as="select" label="Parent department" :options="$parentOptions" placeholder="Top level" helper="Nest a team under another department." />
            <x-mfield name="description" as="textarea" label="Description" :rows="2" />
            <x-mfield name="is_active" as="toggle" label="Status" helper="Active (available for new assignments)" />
        </form>
        <x-slot:footer>
            <x-button variant="outline" @click="hide()">Cancel</x-button>
            <x-button type="submit" form="department-form">Save department</x-button>
        </x-slot:footer>
    </x-modal>
@endif
@endsection
