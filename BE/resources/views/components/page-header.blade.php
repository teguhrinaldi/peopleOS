@props(['title', 'subtitle' => null, 'breadcrumbs' => []])
<div {{ $attributes->class(['mb-6 flex flex-wrap items-start justify-between gap-4']) }}>
    <div class="min-w-0">
        @if ($breadcrumbs)
            <nav aria-label="Breadcrumb" class="mb-1.5 flex flex-wrap items-center gap-1.5 text-xs text-ink-3 no-print">
                @foreach ($breadcrumbs as $crumb)
                    @if (! $loop->first)<x-icon name="chevron-right" class="size-3" />@endif
                    @if (! empty($crumb[1]) && ! $loop->last)<a href="{{ $crumb[1] }}" class="hover:text-primary-ink">{{ $crumb[0] }}</a>@else<span class="{{ $loop->last ? 'text-ink-2' : '' }}">{{ $crumb[0] }}</span>@endif
                @endforeach
            </nav>
        @endif
        <h1 class="text-2xl font-bold tracking-tight text-ink">{{ $title }}</h1>
        @if ($subtitle)<p class="mt-1 text-[13.5px] text-ink-3">{{ $subtitle }}</p>@endif
    </div>
    @if (trim((string) $slot) !== '')<div class="flex flex-wrap items-center gap-2 no-print">{{ $slot }}</div>@endif
</div>
