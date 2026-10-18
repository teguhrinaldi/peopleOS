@props(['title' => null, 'subtitle' => null, 'padded' => true])
<section {{ $attributes->class(['card']) }}>
    @if ($title || isset($actions))
        <header class="flex items-start justify-between gap-3 px-5 pt-4 pb-4">
            <div class="min-w-0">
                @if ($title)<h2 class="text-[15px] font-semibold text-ink">{{ $title }}</h2>@endif
                @if ($subtitle)<p class="mt-0.5 text-[12.5px] text-ink-3">{{ $subtitle }}</p>@endif
            </div>
            @isset($actions)<div class="flex shrink-0 items-center gap-2 no-print">{{ $actions }}</div>@endisset
        </header>
    @endif
    <div @class(['px-5 pb-5' => $padded, 'px-5 pt-5' => $padded && ! $title && ! isset($actions)])>{{ $slot }}</div>
    @isset($footer)
        <footer class="border-t border-line px-5 py-3">{{ $footer }}</footer>
    @endisset
</section>
