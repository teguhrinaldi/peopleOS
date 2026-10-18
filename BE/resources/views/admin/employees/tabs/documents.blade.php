@php
    use App\Support\DateDisplay;
    $canUpload = auth()->user()->hasPermission('document.manage') && ! $employee->isTerminated();
    $icons = ['pdf' => 'file-text', 'jpg' => 'file-image', 'jpeg' => 'file-image', 'png' => 'file-image', 'xlsx' => 'file-spreadsheet', 'docx' => 'file-text'];
@endphp
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <p class="text-[13px] text-ink-3">Documents of {{ $employee->full_name }}. Company-wide documents are listed under Documents.</p>
    @if ($canUpload)<x-button icon="upload" :dispatch="['name' => 'upload']">Upload document</x-button>@endif
</div>

<x-data-table :empty="$docs['list']->isEmpty()" emptyIcon="folder" emptyTitle="No documents yet" emptyText="Contracts, identity references and certificates for this employee appear here.">
    @if ($canUpload)
        <x-slot:emptyAction><x-button icon="upload" :dispatch="['name' => 'upload']">Upload the first document</x-button></x-slot:emptyAction>
    @endif
    <x-slot:head><th>Document</th><th class="hidden md:table-cell">Type</th><th>Status</th><th class="hidden sm:table-cell">Expiry</th><th class="w-10"><span class="sr-only">Open</span></th></x-slot:head>
    @foreach ($docs['list'] as $d)
        @php $status = $docs['desk']->status($d); @endphp
        <tr>
            <td><a href="{{ route('admin.documents.show', $d) }}" class="flex items-center gap-3"><span class="grid size-9 shrink-0 place-items-center rounded-lg bg-primary-soft text-primary-ink"><x-icon :name="$icons[$d->file_extension] ?? 'file-text'" class="size-[18px]" /></span>
                <span class="min-w-0"><span class="block truncate font-semibold text-ink hover:text-primary-ink">{{ $d->title }}</span><span class="block truncate text-xs text-ink-3">{{ $d->original_name }}</span></span></a></td>
            <td class="hidden md:table-cell">{{ $d->type?->name }}</td>
            <td><x-doc-status :status="$status" :days="$docs['desk']->daysLeft($d)" /></td>
            <td class="hidden whitespace-nowrap sm:table-cell">{{ $d->expiry_date ? DateDisplay::date($d->expiry_date) : '—' }}</td>
            <td><a href="{{ route('admin.documents.file', $d) }}" class="grid size-8 place-items-center rounded-lg text-ink-3 hover:bg-surface-3 hover:text-ink" aria-label="Download {{ $d->title }}"><x-icon name="download" class="size-4" /></a></td>
        </tr>
    @endforeach
</x-data-table>

@if ($canUpload)
    @include('admin.documents._upload', ['employeeId' => $employee->id, 'uploadTypes' => $docs['types'], 'employees' => []])
@endif
