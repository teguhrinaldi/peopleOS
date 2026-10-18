@props(['name', 'title' => null, 'width' => 'max-w-xl'])
{{-- Right-hand detail panel. Open with $dispatch('open-modal', { name, data }); the payload is `data`. --}}
<div x-data="modal(@js($name))" x-show="open" x-cloak @open-modal.window="show($event)" @keydown.escape.window="hide()"
    class="fixed inset-0 z-50 no-print" role="dialog" aria-modal="true" aria-labelledby="{{ $name }}-title">
    <div class="absolute inset-0 bg-[#050b1c]/50" x-show="open" x-transition.opacity @click="hide()"></div>
    <aside x-show="open" x-trap.noscroll="open"
        x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
        x-transition:leave="transition duration-150 ease-in" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
        class="absolute inset-y-0 right-0 flex w-full {{ $width }} flex-col border-l border-line bg-surface shadow-pop">
        <header class="flex items-start justify-between gap-4 border-b border-line px-6 py-4">
            <h2 id="{{ $name }}-title" class="text-base font-semibold text-ink" x-text="data.title || @js($title)">{{ $title }}</h2>
            <button type="button" @click="hide()" class="-mr-2 grid size-8 place-items-center rounded-lg text-ink-3 hover:bg-surface-3 hover:text-ink" aria-label="Close"><x-icon name="x" class="size-4" /></button>
        </header>
        <div class="flex-1 overflow-y-auto px-6 py-5">{{ $slot }}</div>
    </aside>
</div>
