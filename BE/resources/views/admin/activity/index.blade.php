@extends('layouts.admin')
@section('title', 'Audit log')

@section('content')
@php
    $tones = ['primary' => 'bg-primary-soft text-primary-ink', 'success' => 'bg-success-soft text-success-ink', 'warning' => 'bg-warning-soft text-warning-ink', 'danger' => 'bg-danger-soft text-danger-ink', 'muted' => 'bg-neutral-soft text-neutral-ink'];
    $isFiltered = collect($filters)->filter()->isNotEmpty();
@endphp

<x-page-header title="Settings" subtitle="Audit log: append-only record of every important change. Nothing here can be edited or deleted."
    :breadcrumbs="[['Settings', auth()->user()->hasPermission('settings.manage') ? route('admin.settings') : null], ['Audit log']]" />

@include('admin.settings._tabs', ['active' => 'audit'])

<form method="GET" action="{{ route('admin.activity') }}" class="card mb-4 grid grid-cols-2 gap-3 p-4 lg:grid-cols-[repeat(5,minmax(0,1fr))_auto] no-print"
    @submit="[...$el.elements].forEach(f => { if (f.name && f.value === '') f.disabled = true })">
    <x-select name="actor_id" label="Actor" :options="$options['actors']" :value="$filters['actor_id'] ?? null" placeholder="Anyone" />
    <x-select name="action" label="Action" :options="$options['actions']" :value="$filters['action'] ?? null" placeholder="Any action" />
    <x-select name="entity_type" label="Entity" :options="$options['entities']" :value="$filters['entity_type'] ?? null" placeholder="Any entity" />
    <x-input name="from" type="date" label="From" :value="$filters['from'] ?? null" />
    <x-input name="to" type="date" label="To" :value="$filters['to'] ?? null" />
    <div class="col-span-2 flex items-end gap-2 lg:col-span-1">
        <x-button type="submit" icon="filter">Apply</x-button>
        @if ($isFiltered)<x-button variant="text" :href="route('admin.activity')">Clear</x-button>@endif
    </div>
</form>

<x-data-table :empty="$activities->isEmpty()" emptyIcon="history" :emptyTitle="$isFiltered ? 'No activity matches these filters' : 'No activity yet'">
    <x-slot:head><th>Time</th><th>Actor</th><th>Action</th><th class="hidden md:table-cell">Entity</th><th class="hidden lg:table-cell">Details</th><th class="w-24"><span class="sr-only">Diff</span></th></x-slot:head>
    @foreach ($activities as $a)
        @php $sheet = ['name' => 'activity-detail', 'data' => [
            'title' => $a['actor'].' '.$a['verb'].($a['subject'] ? ' '.$a['subject'] : ''),
            'action' => $a['action'], 'entity' => $a['entity'], 'at' => $a['at']->format('d M Y, H:i:s T'), 'ip' => $a['ip'] ?? '—',
            'diff' => $a['diff'], 'description' => $a['description'],
        ]]; @endphp
        <tr>
            <td class="whitespace-nowrap tabular-nums"><time datetime="{{ $a['at']->toIso8601String() }}">{{ $a['at']->format('d M, H:i') }}</time></td>
            <td class="whitespace-nowrap font-medium text-ink">{{ $a['actor'] }}</td>
            <td class="whitespace-nowrap"><span class="inline-flex items-center gap-2"><span class="grid size-6 place-items-center rounded-full {{ $tones[$a['tone']] }}"><x-icon :name="$a['icon']" class="size-3.5" /></span><code class="text-xs text-ink-2">{{ $a['action'] }}</code></span></td>
            <td class="hidden whitespace-nowrap md:table-cell">
                @if ($a['subject_url'] && $a['subject'])<a href="{{ $a['subject_url'] }}" class="font-medium text-primary-ink hover:underline">{{ $a['subject'] }}</a>
                @else<span class="text-ink">{{ $a['subject'] ?? '' }}</span>@endif
                <span class="block text-xs text-ink-3">{{ $a['entity'] }}</span>
            </td>
            <td class="hidden max-w-96 lg:table-cell"><span class="line-clamp-2 text-xs">{{ $a['changes'] ? implode(' · ', $a['changes']) : ucfirst($a['verb']) }}</span></td>
            <td class="text-right">@if ($a['diff'])<x-button variant="text" size="sm" :dispatch="$sheet">Changes</x-button>@endif</td>
        </tr>
    @endforeach
    <x-slot:footer><x-pagination :paginator="$activities" /></x-slot:footer>
</x-data-table>

<x-sheet name="activity-detail" title="Change details">
    <dl class="grid grid-cols-[110px_1fr] gap-x-4 gap-y-2 text-[13px]">
        <dt class="text-ink-3">When</dt><dd class="text-ink" x-text="data.at"></dd>
        <dt class="text-ink-3">Action</dt><dd><code class="text-xs text-ink-2" x-text="data.action"></code></dd>
        <dt class="text-ink-3">Entity</dt><dd class="text-ink" x-text="data.entity"></dd>
        <dt class="text-ink-3">IP address</dt><dd class="font-mono text-xs text-ink" x-text="data.ip"></dd>
    </dl>
    <h3 class="mb-2 mt-6 text-[13px] font-semibold text-ink">Values</h3>
    <div class="overflow-hidden rounded-xl border border-line">
        <table class="dt">
            <thead><tr><th>Field</th><th>Before</th><th>After</th></tr></thead>
            <tbody>
                <template x-for="row in (data.diff || [])" :key="row.key">
                    <tr>
                        <td class="font-medium text-ink" x-text="row.key"></td>
                        <td class="break-all text-xs" :class="row.changed ? 'text-danger-ink line-through decoration-danger/40' : ''" x-text="row.old"></td>
                        <td class="break-all text-xs" :class="row.changed ? 'font-semibold text-success-ink' : ''" x-text="row.new"></td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
    <p class="mt-3 text-xs text-ink-3">Passwords, tokens and selfie paths are redacted before anything is stored.</p>
</x-sheet>
@endsection
