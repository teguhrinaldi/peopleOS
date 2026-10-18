@extends('layouts.admin')
@section('title', $document->title)

@section('content')
@php
    use App\Support\DateDisplay;
    $status = $desk->status($document);
    $days = $desk->daysLeft($document);
    $archived = $status->value === 'ARCHIVED';
    $previewable = in_array($document->file_extension, \App\Services\Admin\DocumentDesk::PREVIEWABLE, true);
    $editOpen = old('_form') === 'edit-doc';
    $archiveOpen = old('_form') === 'archive-doc';
    $del = ['title' => 'Delete '.$document->title.'?', 'text' => 'The document disappears from every list and from the employee app. The file stays in private storage for retention and the deletion is recorded in the audit trail.', 'action' => route('admin.documents.destroy', $document), 'method' => 'DELETE', 'label' => 'Delete document'];
@endphp
<x-page-header :title="$document->title" :subtitle="$document->original_name" :breadcrumbs="[['Documents', route('admin.documents')], [$document->title]]">
    <x-doc-status :status="$status" :days="$days" />
    @if ($previewable)<x-button variant="outline" icon="eye" :href="route('admin.documents.file', [$document, 'preview' => 1])" target="_blank" rel="noopener">Preview</x-button>@endif
    <x-button icon="download" :href="route('admin.documents.file', $document)">Download</x-button>
    @if ($canManage)
        <x-dropdown width="w-52">
            <x-slot:trigger><x-button variant="outline" iconRight="chevron-down">Manage</x-button></x-slot:trigger>
            @unless ($archived)<x-dropdown-item icon="pencil" :dispatch="['name' => 'edit-doc']">Edit details</x-dropdown-item>@endunless
            @if ($archived)
                <form method="POST" action="{{ route('admin.documents.restore', $document) }}">@csrf<x-dropdown-item type="submit" icon="archive-restore">Restore</x-dropdown-item></form>
            @else
                <x-dropdown-item icon="archive" :dispatch="['name' => 'archive-doc']">Archive</x-dropdown-item>
            @endif
            <x-dropdown-item danger icon="trash-2" :dispatch="['name' => 'confirm', 'data' => $del]">Delete</x-dropdown-item>
        </x-dropdown>
    @endif
</x-page-header>

@if ($archived)
    <x-alert tone="info" icon="archive" title="Archived" class="mb-6">Hidden from employees{{ $document->archive_reason ? ': '.$document->archive_reason : '.' }} Archived by {{ $document->archiver?->name ?? 'someone' }} on {{ DateDisplay::moment($document->archived_at) }}.</x-alert>
@elseif ($status->value === 'EXPIRED')
    <x-alert tone="danger" title="This document has expired" class="mb-6">It expired on {{ DateDisplay::date($document->expiry_date) }}. Upload a renewed copy and archive this one.</x-alert>
@elseif ($status->value === 'EXPIRING')
    <x-alert tone="warning" title="Expires soon" class="mb-6">It expires on {{ DateDisplay::date($document->expiry_date) }} ({{ $days }} day(s) left).</x-alert>
@endif

<div class="grid grid-cols-1 gap-6 lg:grid-cols-5">
    <x-card title="Details" class="lg:col-span-3">
        <x-kv cols="2" :items="[
            'Type' => $document->type?->name,
            'Belongs to' => $document->employee ? $document->employee->full_name.' ('.$document->employee->employee_number.')' : 'Company',
            'Expiry date' => $document->expiry_date ? DateDisplay::date($document->expiry_date) : 'No expiry',
            'Visible to employee' => $document->visible_to_employee ? 'Yes' : 'No (HR only)',
            'File' => strtoupper($document->file_extension).' · '.number_format($document->size_bytes / 1024, 0).' KB',
            'Uploaded' => DateDisplay::moment($document->uploaded_at).' by '.($document->uploader?->name ?? '—'),
            'Checksum (SHA-256)' => \Illuminate\Support\Str::limit((string) $document->sha256, 20, '…'),
        ]" />
        @if ($document->notes)<p class="mt-4 whitespace-pre-line border-t border-line pt-3 text-[13px] text-ink-2"><span class="text-ink-3">Notes:</span> {{ $document->notes }}</p>@endif
        @if ($document->employee && auth()->user()->hasAnyPermission('employee.view'))
            <p class="mt-4"><x-button variant="text" size="sm" iconRight="chevron-right" :href="route('admin.employees.show', [$document->employee, 'tab' => 'documents'])">All documents of {{ $document->employee->full_name }}</x-button></p>
        @endif
    </x-card>
    <x-card title="Audit trail" subtitle="Every change, newest first." class="lg:col-span-2">
        @php $items = $trail->map(fn ($a) => ['title' => $a['actor'].' '.$a['verb'], 'meta' => DateDisplay::moment($a['at']), 'body' => implode(' · ', $a['changes']), 'tone' => $a['tone'], 'icon' => $a['icon']])->all(); @endphp
        @if ($items)<x-timeline :items="$items" />@else<p class="py-6 text-center text-[13px] text-ink-3">No activity recorded.</p>@endif
    </x-card>
</div>

@if ($canManage && ! $archived)
    <x-modal name="edit-doc" size="lg" title="Edit document details" description="The file itself cannot be replaced. Upload a new document and archive this one instead." :open="$editOpen">
        <form id="edit-doc-form" method="POST" action="{{ route('admin.documents.update', $document) }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @csrf @method('PUT')
            <input type="hidden" name="_form" value="edit-doc">
            <x-input name="title" label="Title" :value="$document->title" maxlength="150" required class="sm:col-span-2" />
            <x-select name="document_type_id" label="Type" :options="$types->pluck('name', 'id')->all()" :value="$document->document_type_id" required />
            <x-input name="expiry_date" type="date" label="Expiry date" :value="$document->expiry_date?->toDateString()" />
            @if ($document->employee_id)<x-checkbox name="visible_to_employee" label="Visible to the employee" :checked="$document->visible_to_employee" class="sm:col-span-2" />@endif
            <x-textarea name="notes" label="Notes" rows="2" maxlength="1000" :value="$document->notes" class="sm:col-span-2" />
        </form>
        <x-slot:footer><x-button variant="outline" @click="hide()">Cancel</x-button><x-button type="submit" form="edit-doc-form">Save details</x-button></x-slot:footer>
    </x-modal>
    <x-modal name="archive-doc" size="md" title="Archive this document?" :open="$archiveOpen">
        <form id="archive-doc-form" method="POST" action="{{ route('admin.documents.archive', $document) }}" class="space-y-4">
            @csrf
            <input type="hidden" name="_form" value="archive-doc">
            <p class="text-[13.5px] text-ink-2">Archived documents disappear from the employee app and are excluded from expiry reminders. Nothing is deleted and you can restore it later.</p>
            <x-textarea name="reason" label="Reason (optional)" rows="2" maxlength="500" placeholder="e.g. Replaced by a renewed certificate" />
        </form>
        <x-slot:footer><x-button variant="outline" @click="hide()">Keep active</x-button><x-button type="submit" form="archive-doc-form" icon="archive">Archive</x-button></x-slot:footer>
    </x-modal>
@endif
@endsection
