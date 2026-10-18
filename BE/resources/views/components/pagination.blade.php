@props(['paginator'])
{{-- Summary on the left, page buttons on the right. Keeps the current query string. --}}
@if ($paginator->total() > 0)
    <p class="text-xs text-ink-3">Showing <span class="font-semibold text-ink-2">{{ $paginator->firstItem() }}&ndash;{{ $paginator->lastItem() }}</span> of <span class="font-semibold text-ink-2">{{ $paginator->total() }}</span></p>
@endif
@if ($paginator->hasPages())
    @php
        $current = $paginator->currentPage();
        $last = $paginator->lastPage();
        $pages = collect([1, $last, $current - 1, $current, $current + 1])->filter(fn ($p) => $p >= 1 && $p <= $last)->unique()->sort()->values();
        $btn = 'grid h-8 min-w-8 place-items-center rounded-lg px-2 text-[13px] font-semibold transition';
    @endphp
    <nav class="flex items-center gap-1" aria-label="Pagination">
        @if ($paginator->onFirstPage())
            <span class="{{ $btn }} text-ink-3/50" aria-disabled="true"><x-icon name="chevron-left" class="size-4" /></span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="{{ $btn }} text-ink-2 hover:bg-surface-3" aria-label="Previous page"><x-icon name="chevron-left" class="size-4" /></a>
        @endif
        @foreach ($pages as $i => $page)
            @if ($i > 0 && $page - $pages[$i - 1] > 1)<span class="px-1 text-ink-3">&hellip;</span>@endif
            @if ($page === $current)
                <span class="{{ $btn }} bg-primary text-white" aria-current="page">{{ $page }}</span>
            @else
                <a href="{{ $paginator->url($page) }}" class="{{ $btn }} text-ink-2 hover:bg-surface-3">{{ $page }}</a>
            @endif
        @endforeach
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="{{ $btn }} text-ink-2 hover:bg-surface-3" aria-label="Next page"><x-icon name="chevron-right" class="size-4" /></a>
        @else
            <span class="{{ $btn }} text-ink-3/50" aria-disabled="true"><x-icon name="chevron-right" class="size-4" /></span>
        @endif
    </nav>
@endif
