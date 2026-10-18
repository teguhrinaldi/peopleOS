@extends('layouts.admin')
@section('title', $employee->full_name)

@section('content')
@php
    use App\Enums\EmployeeStatus as S;
    $status = $employee->status;
    $terminated = $employee->isTerminated();
    $canEdit = ! $terminated && Gate::allows('update', $employee);
    $canDelete = $status === S::Pending && Gate::allows('delete', $employee);
    $tabLinks = collect($tabs)->map(fn ($t) => $t + ['href' => route('admin.employees.show', [$employee, 'tab' => $t['key']])])->all();
    $deactivate = ['name' => 'confirm', 'data' => [
        'title' => 'Deactivate '.$employee->full_name.'?',
        'text' => 'They will not be able to check in or sign in until you activate them again. Their history is kept.',
        'action' => route('admin.employees.status', $employee), 'fields' => ['status' => 'INACTIVE'], 'label' => 'Deactivate',
    ]];
    $delete = ['name' => 'confirm', 'data' => [
        'title' => 'Delete '.$employee->full_name.'?',
        'text' => 'This pending employee has no history and will be removed from the directory. The deletion is recorded in the audit trail.',
        'action' => route('admin.employees.destroy', $employee), 'method' => 'DELETE', 'label' => 'Delete employee',
    ]];
@endphp

<div class="mb-4">
    <a href="{{ route('admin.employees.index') }}" class="no-print inline-flex items-center gap-1.5 text-[13px] font-medium text-ink-3 hover:text-primary-ink"><x-icon name="arrow-left" class="size-4" />Back to employees</a>
</div>

@if ($terminated)
    <x-alert tone="warning" title="Employment ended" class="mb-4">
        Ended on {{ $employee->terminated_at?->format('d M Y') }}@if ($employee->termination_reason) &mdash; {{ $employee->termination_reason }}@endif.
        This record is read-only and kept for history.
    </x-alert>
@endif

<x-card class="mb-6">
    <div class="flex flex-wrap items-start justify-between gap-5">
        <div class="flex min-w-0 items-center gap-5">
            <x-avatar :name="$employee->full_name" :src="$employee->photo_path" size="xl" />
            <div class="min-w-0">
                <h1 class="truncate text-2xl font-bold tracking-tight text-ink">{{ $employee->full_name }}</h1>
                <p class="mt-0.5 text-[13.5px] text-ink-3">{{ $employee->employee_number }}@if ($placement?->position) &middot; {{ $placement->position->name }}@endif</p>
                <div class="mt-2.5 flex flex-wrap items-center gap-2">
                    <x-badge :status="$status" />
                    <span class="text-xs text-ink-3">Joined {{ $employee->join_date->format('d M Y') }}</span>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2 no-print">
            @if ($canEdit)
                <x-button variant="outline" :href="route('admin.employees.edit', $employee)" icon="pencil">Edit</x-button>
            @endif
            @if ($canEdit || $canDelete)
                <x-dropdown width="w-56">
                    <x-slot:trigger><x-button variant="outline" iconRight="chevron-down">Actions</x-button></x-slot:trigger>
                    @if ($canEdit)
                        <x-dropdown-item icon="git-branch" :dispatch="['name' => 'reassign']">Change assignment</x-dropdown-item>
                        @if ($status === S::Active)
                            <x-dropdown-item icon="user-minus" :dispatch="$deactivate">Deactivate</x-dropdown-item>
                        @else
                            <form method="POST" action="{{ route('admin.employees.status', $employee) }}">@csrf<input type="hidden" name="status" value="ACTIVE">
                                <x-dropdown-item type="submit" icon="user-check">Activate</x-dropdown-item></form>
                        @endif
                        <x-dropdown-item danger icon="user-x" :dispatch="['name' => 'terminate']">Terminate employment</x-dropdown-item>
                    @endif
                    @if ($canDelete)
                        <x-dropdown-item danger icon="trash-2" :dispatch="$delete">Delete</x-dropdown-item>
                    @endif
                </x-dropdown>
            @endif
        </div>
    </div>
</x-card>

<x-tabs :tabs="$tabLinks" :active="$tab" class="mb-6" />

@switch($tab)
    @case('employment') @include('admin.employees.tabs.employment') @break
    @case('attendance') @include('admin.employees.tabs.attendance') @break
    @case('activity') @include('admin.employees.tabs.activity') @break
    @case('leave') @include('admin.employees.tabs.leave') @break
    @case('payroll') @include('admin.employees.tabs.payroll') @break
    @case('documents') @include('admin.employees.tabs.documents') @break
    @default @include('admin.employees.tabs.overview')
@endswitch

@if ($canEdit)
    @include('admin.employees.modals')
@endif
@endsection
