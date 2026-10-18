@extends('layouts.admin')
@section('title', 'Employees')

@section('content')
@php
    $sort = $filters['sort'] ?? 'name';
    $dir = $filters['dir'] ?? 'asc';
    $isTeam = ! auth()->user()->hasPermission('employee.view');
    // Mirrors EmployeePolicy::update for every listed row (edit needs employee.edit plus company-wide view);
    // asking the policy per row would cost one query each, and the edit route re-checks it anyway.
    $canEdit = auth()->user()->hasPermission('employee.edit') && ! $isTeam;
    $statusOptions = collect(\App\Enums\EmployeeStatus::cases())->mapWithKeys(fn ($s) => [$s->value => ucfirst(strtolower($s->value))])->all();
    $typeOptions = collect(\App\Enums\EmploymentType::cases())->mapWithKeys(fn ($t) => [$t->value => \Illuminate\Support\Str::headline($t->value)])->all();
@endphp

<x-page-header title="Employees" :subtitle="$isTeam ? 'Your direct reports' : 'Everyone in the company, past and present'">
    @can('employee.create')
        <x-button :href="route('admin.employees.create')" icon="plus">Add employee</x-button>
    @endcan
</x-page-header>

<x-filter-bar :action="route('admin.employees.index')" :reset="route('admin.employees.index')" :q="$filters['q'] ?? ''" placeholder="Search by name, number or email" :active="$isFiltered" class="mb-4">
    <x-select name="department_id" :options="$options['departments']" :value="$filters['department_id'] ?? null" placeholder="All departments" class="w-full sm:w-52" aria-label="Department" />
    <x-select name="position_id" :options="$options['positions']" :value="$filters['position_id'] ?? null" placeholder="All positions" class="w-full sm:w-52" aria-label="Position" />
    <x-select name="work_location_id" :options="$options['locations']" :value="$filters['work_location_id'] ?? null" placeholder="All locations" class="w-full sm:w-48" aria-label="Location" />
    <x-select name="manager_id" :options="$options['managers']" :value="$filters['manager_id'] ?? null" placeholder="All managers" class="w-full sm:w-48" aria-label="Manager" />
    <x-select name="shift_id" :options="$options['shifts']" :value="$filters['shift_id'] ?? null" placeholder="All shifts" class="w-full sm:w-44" aria-label="Shift" />
    <x-select name="status" :options="$statusOptions" :value="$filters['status'] ?? null" placeholder="Any status" class="w-full sm:w-40" aria-label="Status" />
    <x-select name="employment_type" :options="$typeOptions" :value="$filters['employment_type'] ?? null" placeholder="Any type" class="w-full sm:w-40" aria-label="Employment type" />
</x-filter-bar>

<x-data-table :empty="$employees->isEmpty()" emptyIcon="users" class="dt-sticky"
    :emptyTitle="$isFiltered ? 'No employees match these filters' : 'No employees yet'"
    :emptyText="$isFiltered ? 'Try a different search or clear the filters.' : ($isTeam ? 'Nobody reports to you at the moment.' : 'Add your first employee to get started.')">
    <x-slot:head>
        <x-sort-th column="name" label="Employee" :sort="$sort" :dir="$dir" />
        <x-sort-th column="department" label="Department" :sort="$sort" :dir="$dir" />
        <x-sort-th column="position" label="Position" :sort="$sort" :dir="$dir" class="hidden sm:table-cell" />
        <th class="hidden lg:table-cell">Manager</th>
        <th class="hidden lg:table-cell">Location</th>
        <th class="hidden xl:table-cell">Shift</th>
        <x-sort-th column="status" label="Status" :sort="$sort" :dir="$dir" />
        <x-sort-th column="join_date" label="Joined" :sort="$sort" :dir="$dir" class="hidden md:table-cell" />
        <th class="w-10"><span class="sr-only">Actions</span></th>
    </x-slot:head>

    @foreach ($employees as $employee)
        @php $a = $employee->currentAssignment; @endphp
        <tr>
            <td>
                <a href="{{ route('admin.employees.show', $employee) }}" class="group flex items-center gap-3">
                    <x-avatar :name="$employee->full_name" :src="$employee->photo_path" size="sm" />
                    <span class="min-w-0">
                        <span class="block truncate font-semibold text-ink group-hover:text-primary-ink">{{ $employee->full_name }}</span>
                        <span class="block text-xs text-ink-3">{{ $employee->employee_number }}</span>
                    </span>
                </a>
            </td>
            <td class="whitespace-nowrap">{{ $a?->department?->name ?? '—' }}</td>
            <td class="hidden whitespace-nowrap sm:table-cell">{{ $a?->position?->name ?? '—' }}</td>
            <td class="hidden whitespace-nowrap lg:table-cell">{{ $a?->manager?->full_name ?? '—' }}</td>
            <td class="hidden whitespace-nowrap lg:table-cell">{{ $a?->workLocation?->name ?? '—' }}</td>
            <td class="hidden whitespace-nowrap xl:table-cell">{{ $a?->shift?->name ?? '—' }}</td>
            <td><x-badge :status="$employee->status" /></td>
            <td class="hidden whitespace-nowrap md:table-cell">{{ \App\Support\DateDisplay::date($employee->join_date) }}</td>
            <td class="text-right">
                <x-dropdown width="w-44">
                    <x-slot:trigger>
                        <button type="button" class="grid size-8 place-items-center rounded-lg text-ink-3 hover:bg-surface-3 hover:text-ink" aria-label="Actions for {{ $employee->full_name }}"><x-icon name="ellipsis-vertical" class="size-4" /></button>
                    </x-slot:trigger>
                    <x-dropdown-item :href="route('admin.employees.show', $employee)" icon="eye">View</x-dropdown-item>
                    @if ($canEdit && ! $employee->isTerminated())
                        <x-dropdown-item :href="route('admin.employees.edit', $employee)" icon="pencil">Edit</x-dropdown-item>
                    @endif
                </x-dropdown>
            </td>
        </tr>
    @endforeach

    <x-slot:footer><x-pagination :paginator="$employees" /></x-slot:footer>
</x-data-table>
@endsection
