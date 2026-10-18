@extends('layouts.admin')
@section('title', 'Reports')

@section('content')
<x-page-header title="Reports" subtitle="Run a report on screen, then export exactly what you see as CSV or PDF. You only see reports you are allowed to run." :breadcrumbs="[['Reports']]" />

@forelse ($catalogue as $group)
    <section class="mb-8" aria-labelledby="g-{{ $loop->index }}">
        <h2 id="g-{{ $loop->index }}" class="mb-3 flex items-center gap-2 text-[15px] font-semibold text-ink"><span class="grid size-8 place-items-center rounded-lg bg-primary-soft text-primary-ink"><x-icon :name="$group['icon']" class="size-4" /></span>{{ $group['label'] }}</h2>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($group['reports'] as $r)
                <a href="{{ route('admin.reports.show', $r->key()) }}" class="card group flex h-full flex-col p-4 transition hover:border-primary/40">
                    <span class="flex items-start justify-between gap-2"><span class="text-[14.5px] font-semibold text-ink group-hover:text-primary-ink">{{ $r->title() }}</span><x-icon name="chevron-right" class="mt-0.5 size-4 shrink-0 text-ink-3" /></span>
                    <span class="mt-1.5 text-[13px] leading-relaxed text-ink-3">{{ $r->description() }}</span>
                </a>
            @endforeach
        </div>
    </section>
@empty
    <x-card><x-empty-state icon="chart-column" title="No reports available" text="Your role does not include any report. Ask an administrator for access to the data you need." /></x-card>
@endforelse
@endsection
