@props(['name', 'title' => null, 'description' => null, 'size' => 'md', 'open' => false, 'initial' => []])
@php $width = ['sm' => 'max-w-sm', 'md' => 'max-w-lg', 'lg' => 'max-w-2xl'][$size] ?? 'max-w-lg'; @endphp
{{-- Open with $dispatch('open-modal', { name: '{{ $name }}', data: {...} }). Inside, `data` holds the payload. --}}
<div x-data="modal(@js($name), @js((bool) $open), @js((object) $initial))" x-show="open" x-cloak
    @open-modal.window="show($event)" @keydown.escape.window="hide()"
    class="fixed inset-0 z-50 grid place-items-center p-4" role="dialog" aria-modal="true" aria-labelledby="{{ $name }}-title">
    <div class="absolute inset-0 bg-[#050b1c]/55 backdrop-blur-[2px]" x-show="open" x-transition.opacity @click="hide()"></div>
    <div x-show="open" x-trap.noscroll="open" x-transition:enter="transition duration-150" x-transition:enter-start="opacity-0 translate-y-2 scale-[0.98]" x-transition:enter-end="opacity-100"
        {{ $attributes->class(["relative flex max-h-[90vh] w-full flex-col overflow-hidden rounded-2xl border border-line bg-surface shadow-pop {$width}"]) }}>
        <header class="flex items-start justify-between gap-4 border-b border-line px-6 py-4">
            <div class="min-w-0">
                <h2 id="{{ $name }}-title" class="text-base font-semibold text-ink" x-text="data.title || @js($title)">{{ $title }}</h2>
                @if ($description)<p class="mt-0.5 text-[12.5px] text-ink-3">{{ $description }}</p>@endif
            </div>
            <button type="button" @click="hide()" class="-mr-2 grid size-8 place-items-center rounded-lg text-ink-3 hover:bg-surface-3 hover:text-ink" aria-label="Close"><x-icon name="x" class="size-4" /></button>
        </header>
        <div class="overflow-y-auto px-6 py-5">{{ $slot }}</div>
        @isset($footer)
            <footer class="flex flex-wrap items-center justify-end gap-2 border-t border-line bg-surface-2 px-6 py-3.5">{{ $footer }}</footer>
        @endisset
    </div>
</div>
