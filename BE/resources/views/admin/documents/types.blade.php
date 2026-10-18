@extends('layouts.admin')
@section('title', 'Document types')

@section('content')
@php
    $blank = ['title' => 'Add document type', 'action' => route('admin.documents.types.store'), 'method' => 'POST', 'name' => '', 'description' => '', 'requires_expiry' => false, 'default_visible_to_employee' => true,
        'allow_employee_documents' => true, 'allow_company_documents' => false, 'is_active' => true, 'sort_order' => ''];
    $reopen = old('_form') === 'doc-type';
    $initial = $reopen ? ['title' => old('_title', 'Document type'), 'action' => old('_action'), 'method' => old('_method', 'POST'), 'name' => old('name'), 'description' => old('description'),
        'requires_expiry' => (bool) old('requires_expiry'), 'default_visible_to_employee' => (bool) old('default_visible_to_employee'), 'allow_employee_documents' => (bool) old('allow_employee_documents'),
        'allow_company_documents' => (bool) old('allow_company_documents'), 'is_active' => (bool) old('is_active'), 'sort_order' => old('sort_order')] : $blank;
@endphp
<x-page-header title="Document Types" subtitle="Categories of documents and the rules for each. Types in use are retired (deactivated), never deleted." :breadcrumbs="[['Documents', route('admin.documents')], ['Types']]">
    <x-button icon="plus" :dispatch="['name' => 'doc-type', 'data' => $blank]">Add type</x-button>
</x-page-header>

<x-data-table :empty="$types->isEmpty()" emptyIcon="folder" emptyTitle="No document types" emptyText="Add a type such as Contract or Certificate before uploading documents.">
    <x-slot:head><th>Type</th><th class="hidden md:table-cell">Rules</th><th class="text-right">Documents</th><th>Status</th><th class="w-20"><span class="sr-only">Actions</span></th></x-slot:head>
    @foreach ($types as $t)
        @php $edit = ['title' => 'Edit '.$t->name, 'action' => route('admin.documents.types.update', $t), 'method' => 'PUT', 'name' => $t->name, 'description' => $t->description ?? '', 'requires_expiry' => $t->requires_expiry,
            'default_visible_to_employee' => $t->default_visible_to_employee, 'allow_employee_documents' => $t->allow_employee_documents, 'allow_company_documents' => $t->allow_company_documents, 'is_active' => $t->is_active, 'sort_order' => (string) $t->sort_order]; @endphp
        <tr>
            <td><span class="font-semibold text-ink">{{ $t->name }}</span><span class="block text-xs text-ink-3">{{ $t->code }}@if ($t->description) &middot; {{ $t->description }}@endif</span></td>
            <td class="hidden md:table-cell">
                <div class="flex flex-wrap gap-1.5">
                    @if ($t->requires_expiry)<x-badge tone="warning" :dot="false">Needs expiry</x-badge>@endif
                    @if ($t->allow_employee_documents)<x-badge tone="neutral" :dot="false">Employee</x-badge>@endif
                    @if ($t->allow_company_documents)<x-badge tone="neutral" :dot="false">Company</x-badge>@endif
                    <x-badge tone="neutral" :dot="false">{{ $t->default_visible_to_employee ? 'Visible by default' : 'HR only by default' }}</x-badge>
                </div>
            </td>
            <td class="text-right tabular-nums">{{ $counts[$t->id] ?? 0 }}</td>
            <td><x-badge :status="$t->is_active ? 'active' : 'inactive'" /></td>
            <td class="text-right"><x-button variant="text" size="sm" icon="pencil" :dispatch="['name' => 'doc-type', 'data' => $edit]">Edit</x-button></td>
        </tr>
    @endforeach
</x-data-table>

<x-modal name="doc-type" size="lg" title="Document type" :initial="$initial" :open="$reopen">
    <form id="doc-type-form" method="POST" :action="data.action" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        @csrf
        <input type="hidden" name="_form" value="doc-type">
        <input type="hidden" name="_method" :value="data.method">
        <input type="hidden" name="_action" :value="data.action">
        <input type="hidden" name="_title" :value="data.title">
        <x-mfield name="name" label="Name" required maxlength="100" placeholder="e.g. Employment contract" />
        <x-mfield name="sort_order" label="Order" type="number" min="0" max="65000" />
        <x-mfield name="description" label="Description" maxlength="255" class="sm:col-span-2" />
        <x-mfield name="requires_expiry" label="Expiry" as="toggle" helper="Uploads of this type must have an expiry date." />
        <x-mfield name="default_visible_to_employee" label="Visibility" as="toggle" helper="Visible to the employee by default." />
        <x-mfield name="allow_employee_documents" label="Employee documents" as="toggle" helper="Can be attached to an employee." />
        <x-mfield name="allow_company_documents" label="Company documents" as="toggle" helper="Can be a company-wide document." />
        <x-mfield name="is_active" label="Active" as="toggle" helper="Inactive types cannot be used for new uploads." />
        @error('allow_employee_documents')<p class="text-xs font-medium text-danger-ink sm:col-span-2">{{ $message }}</p>@enderror
    </form>
    <x-slot:footer><x-button variant="outline" @click="hide()">Cancel</x-button><x-button type="submit" form="doc-type-form">Save type</x-button></x-slot:footer>
</x-modal>
@endsection
