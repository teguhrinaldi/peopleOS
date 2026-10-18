@extends('layouts.admin')
@section('title', 'Salary structures')

@section('content')
<x-page-header title="Salary Structures" subtitle="Reusable templates of salary components. Assigning one copies its values into each employee's dated salary history." :breadcrumbs="[['Payroll'], ['Salary Structures']]">
    @if ($canManage)<x-button icon="plus" :href="route('admin.salary-structures.create')">New structure</x-button>@endif
</x-page-header>

@include('admin.salary._header', ['tab' => 'structures'])

<x-data-table :empty="$structures->isEmpty()" emptyIcon="layers" emptyTitle="No salary structures yet"
    emptyText="Create the components first (basic salary, allowances, deductions), then group them into a structure such as Staff or Manager.">
    @if ($canManage)
        <x-slot:emptyAction><x-button icon="plus" :href="route('admin.salary-structures.create')">Create a structure</x-button></x-slot:emptyAction>
    @endif
    <x-slot:head><th>Structure</th><th class="hidden md:table-cell">Description</th><th class="text-right">Components</th><th class="text-right">Employees now</th><th>Status</th><th class="w-10"><span class="sr-only">Open</span></th></x-slot:head>
    @foreach ($structures as $s)
        <tr>
            <td><a href="{{ route('admin.salary-structures.show', $s) }}" class="block font-semibold text-ink hover:text-primary-ink">{{ $s->name }}</a><span class="text-xs text-ink-3">{{ $s->code }}</span></td>
            <td class="hidden max-w-sm truncate md:table-cell">{{ $s->description ?? '—' }}</td>
            <td class="text-right tabular-nums">{{ $s->lines_count }}</td>
            <td class="text-right tabular-nums">{{ $s->assigned_count }}</td>
            <td><x-badge :status="$s->is_active ? 'active' : 'inactive'" /></td>
            <td><a href="{{ route('admin.salary-structures.show', $s) }}" class="grid size-8 place-items-center rounded-lg text-ink-3 hover:bg-surface-3 hover:text-ink" aria-label="Open {{ $s->name }}"><x-icon name="chevron-right" class="size-4" /></a></td>
        </tr>
    @endforeach
</x-data-table>
@endsection
