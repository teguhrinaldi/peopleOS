@props(['icon' => 'search', 'title', 'text' => null])
<div {{ $attributes->class(['flex flex-col items-center px-6 py-14 text-center']) }}>
    <span class="grid size-14 place-items-center rounded-2xl bg-primary-soft text-primary-ink"><x-icon :name="$icon" class="size-6" /></span>
    <h3 class="mt-4 text-[15px] font-semibold text-ink">{{ $title }}</h3>
    @if ($text)<p class="mt-1 max-w-md text-[13px] leading-relaxed text-ink-3">{{ $text }}</p>@endif
    @if (trim((string) $slot) !== '')<div class="mt-5 flex flex-wrap items-center justify-center gap-2">{{ $slot }}</div>@endif
</div>
