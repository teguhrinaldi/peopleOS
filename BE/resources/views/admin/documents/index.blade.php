@extends('layouts.admin')
@section('title', 'Documents')

@section('content')
@php
    use App\Support\DateDisplay;
    $active = collect($filters)->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty();
    $icons = ['pdf' => 'file-text', 'jpg' => 'file-image', 'jpeg' => 'file-image', 'png' => 'file-image', 'xlsx' => 'file-spreadsheet', 'docx' => 'file-text'];
@endphp

<x-page-header title="Documents" subtitle="Contracts, certificates and company files, stored privately. Expiry is tracked automatically." :breadcrumbs="[['Documents']]">
    @if ($canManage)
        <x-button variant="outline" icon="list-checks" :href="route('admin.documents.types')">Document types</x-button>
        <x-button icon="upload" :dispatch="['name' => 'upload']">Upload document</x-button>
    @endif
</x-page-header>

<x-filter-bar :action="route('admin.documents')" :reset="route('admin.documents')" :q="$filters['q'] ?? ''" placeholder="Search by title or file name" :active="$active" class="mb-4">
    <x-listbox class="w-full md:w-44"><select name="document_type_id" class="ctl" aria-label="Type"><option value="">All types</option>
        @foreach ($options['types'] as $id => $name)<option value="{{ $id }}" @selected((string) ($filters['document_type_id'] ?? '') === (string) $id)>{{ $name }}</option>@endforeach</select></x-listbox>
    <x-listbox class="w-full md:w-40"><select name="status" class="ctl" aria-label="Status"><option value="">Any status</option>
        @foreach ($desk::STATUSES as $v => $l)<option value="{{ $v }}" @selected(($filters['status'] ?? '') === $v)>{{ $l }}</option>@endforeach</select></x-listbox>
    <x-listbox class="w-full md:w-44"><select name="scope" class="ctl" aria-label="Belongs to"><option value="">All documents</option>
        <option value="company" @selected(($filters['scope'] ?? '') === 'company')>Company only</option><option value="employee" @selected(($filters['scope'] ?? '') === 'employee')>Employees only</option></select></x-listbox>
    <x-listbox class="w-full md:w-52"><select name="employee_id" class="ctl" aria-label="Employee"><option value="">All employees</option>
        @foreach ($options['employees'] as $id => $name)<option value="{{ $id }}" @selected((string) ($filters['employee_id'] ?? '') === (string) $id)>{{ $name }}</option>@endforeach</select></x-listbox>
    <x-listbox class="w-full md:w-44"><select name="expiring_within_days" class="ctl" aria-label="Expiring within"><option value="">Any expiry</option>
        @foreach ([7 => '7 days', 30 => '30 days', 60 => '60 days', 90 => '90 days'] as $d => $l)<option value="{{ $d }}" @selected((string) ($filters['expiring_within_days'] ?? '') === (string) $d)>Expiring within {{ $l }}</option>@endforeach</select></x-listbox>
</x-filter-bar>

<x-data-table :empty="$documents->isEmpty()" emptyIcon="folder" :emptyTitle="$active ? 'No documents match' : 'No documents yet'"
    :emptyText="$active ? 'Try different filters or clear them.' : 'Upload contracts, certificates and company policies. Employees see what you mark as visible in their app.'">
    @if (! $active && $canManage)
        <x-slot:emptyAction><x-button icon="upload" :dispatch="['name' => 'upload']">Upload the first document</x-button></x-slot:emptyAction>
    @endif
    <x-slot:head>
        <th>Document</th><th class="hidden md:table-cell">Type</th><th class="hidden lg:table-cell">Belongs to</th><th>Status</th><th class="hidden sm:table-cell">Expiry</th><th class="w-10"><span class="sr-only">Open</span></th>
    </x-slot:head>
    @foreach ($documents as $d)
        @php $status = $desk->status($d); $days = $desk->daysLeft($d); @endphp
        <tr>
            <td>
                <a href="{{ route('admin.documents.show', $d) }}" class="flex items-center gap-3">
                    <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-primary-soft text-primary-ink"><x-icon :name="$icons[$d->file_extension] ?? 'file-text'" class="size-[18px]" /></span>
                    <span class="min-w-0"><span class="block truncate font-semibold text-ink hover:text-primary-ink">{{ $d->title }}</span><span class="block truncate text-xs text-ink-3">{{ $d->original_name }} &middot; {{ number_format($d->size_bytes / 1024, 0) }} KB</span></span>
                </a>
            </td>
            <td class="hidden md:table-cell">{{ $d->type?->name }}</td>
            <td class="hidden lg:table-cell">@if ($d->employee){{ $d->employee->full_name }}<span class="block text-xs text-ink-3">{{ $d->employee->employee_number }}</span>@else<x-badge tone="info" :dot="false">Company</x-badge>@endif</td>
            <td><x-doc-status :status="$status" :days="$days" /></td>
            <td class="hidden whitespace-nowrap sm:table-cell">{{ $d->expiry_date ? DateDisplay::date($d->expiry_date) : '—' }}@if ($days !== null && $status->value === 'EXPIRED')<span class="block text-xs text-danger-ink">{{ abs($days) }} d ago</span>@endif</td>
            <td><a href="{{ route('admin.documents.show', $d) }}" class="grid size-8 place-items-center rounded-lg text-ink-3 hover:bg-surface-3 hover:text-ink" aria-label="Open {{ $d->title }}"><x-icon name="chevron-right" class="size-4" /></a></td>
        </tr>
    @endforeach
    <x-slot:footer><x-pagination :paginator="$documents" /></x-slot:footer>
</x-data-table>

@if ($canManage)
    @include('admin.documents._upload')
@endif
@endsection
