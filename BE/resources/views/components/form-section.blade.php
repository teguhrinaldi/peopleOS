@props(['title', 'description' => null])
<section {{ $attributes->class(['card grid gap-x-8 gap-y-4 p-5 lg:grid-cols-[260px_1fr]']) }}>
    <div>
        <h3 class="text-[15px] font-semibold text-ink">{{ $title }}</h3>
        @if ($description)<p class="mt-1 text-[12.5px] leading-relaxed text-ink-3">{{ $description }}</p>@endif
    </div>
    <div class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-2">{{ $slot }}</div>
</section>
