@extends('layouts.admin')
@section('title', 'Positions')

@section('content')
@php
    $canManage = auth()->user()->hasPermission('organization.manage');
    $blank = ['id' => null, 'action' => route('admin.positions.store'), 'title' => 'New position', 'code' => '', 'name' => '', 'level' => '', 'description' => '', 'is_active' => true];
    $reopen = old('_form') === 'position';
    $initial = $reopen ? [
        'id' => old('_id'), 'action' => old('_action'), 'title' => old('_id') ? 'Edit position' : 'New position',
        'code' => old('code'), 'name' => old('name'), 'level' => old('level'), 'description' => old('description'), 'is_active' => (bool) old('is_active'),
    ] : $blank;
@endphp

<x-page-header title="Positions" subtitle="Job titles and levels used when placing employees.">
    @if ($canManage)<x-button icon="plus" :dispatch="['name' => 'position-form', 'data' => $blank]">Add position</x-button>@endif
</x-page-header>

<x-filter-bar :action="route('admin.positions.index')" :reset="route('admin.positions.index')" :q="$q" placeholder="Search by title or code" :active="$q !== '' || $state" class="mb-4">
    <x-select name="state" :options="['active' => 'Active only', 'inactive' => 'Inactive only']" :value="$state" placeholder="Any state" class="w-full sm:w-44" aria-label="State" />
</x-filter-bar>

<x-data-table :empty="$positions->isEmpty()" emptyIcon="briefcase" emptyTitle="No positions found" emptyText="Create a position to describe what people do.">
    <x-slot:head>
        <th>Position</th><th>Level</th><th class="text-right">Employees</th><th>Status</th>
        @if ($canManage)<th class="w-24"><span class="sr-only">Actions</span></th>@endif
    </x-slot:head>
    @foreach ($positions as $p)
        @php
            $count = $headcount[$p->id] ?? 0;
            $edit = ['id' => $p->id, 'action' => route('admin.positions.update', $p), 'title' => 'Edit position', 'code' => $p->code, 'name' => $p->name,
                'level' => (string) $p->level, 'description' => (string) $p->description, 'is_active' => $p->is_active];
            $del = ['title' => 'Delete '.$p->name.'?', 'text' => $count ? $p->name.' is still held by '.$count.' '.\Illuminate\Support\Str::plural('employee', $count).'. Reassign them first, or deactivate the position instead.' : 'The position will be removed. This is recorded in the audit trail.', 'action' => route('admin.positions.destroy', $p), 'method' => 'DELETE', 'label' => 'Delete position'];
        @endphp
        <tr>
            <td><span class="block font-semibold text-ink">{{ $p->name }}</span><span class="block text-xs text-ink-3">{{ $p->code }}</span></td>
            <td>{{ $p->level ? 'Level '.$p->level : '—' }}</td>
            <td class="text-right font-semibold tabular-nums text-ink">{{ $count }}</td>
            <td><x-badge :status="$p->is_active ? 'active' : 'inactive'" /></td>
            @if ($canManage)
                <td class="whitespace-nowrap text-right">
                    <x-button variant="text" size="sm" icon="pencil" :dispatch="['name' => 'position-form', 'data' => $edit]" :aria-label="'Edit '.$p->name" />
                    <x-button variant="text" size="sm" icon="trash-2" class="text-danger-ink" :dispatch="['name' => 'confirm', 'data' => $del]" :aria-label="'Delete '.$p->name" />
                </td>
            @endif
        </tr>
    @endforeach
    <x-slot:footer><x-pagination :paginator="$positions" /></x-slot:footer>
</x-data-table>

@if ($canManage)
    <x-modal name="position-form" :open="$reopen" :initial="$initial" title="Position">
        <form id="position-form" method="POST" :action="data.action" class="space-y-4">
            @csrf
            <input type="hidden" name="_form" value="position">
            <input type="hidden" name="_id" :value="data.id">
            <input type="hidden" name="_action" :value="data.action">
            <template x-if="data.id"><input type="hidden" name="_method" value="PUT"></template>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <x-mfield name="code" label="Code" required placeholder="SWE" class="sm:col-span-1" />
                <x-mfield name="name" label="Title" required placeholder="Software Engineer" class="sm:col-span-2" />
            </div>
            <x-mfield name="level" type="number" label="Level" min="1" max="20" helper="Optional seniority, 1 (entry) to 20." />
            <x-mfield name="description" as="textarea" label="Description" :rows="2" />
            <x-mfield name="is_active" as="toggle" label="Status" helper="Active (available for new assignments)" />
        </form>
        <x-slot:footer>
            <x-button variant="outline" @click="hide()">Cancel</x-button>
            <x-button type="submit" form="position-form">Save position</x-button>
        </x-slot:footer>
    </x-modal>
@endif
@endsection
