@props(['event', 'label' => 'Details', 'width' => 'max-w-xl'])
{{-- Right-hand detail panel. Open with $dispatch('{event}', { url, title }); it loads the escaped, server-rendered partial. --}}
<div x-data="leaveDrawer" x-show="open" x-cloak x-on:{{ $event }}.window="load($event.detail)"@keydown.escape.window="open = false"
    class="fixed inset-0 z-50 no-print" role="dialog" aria-modal="true" aria-labelledby="{{ $event }}-title">
    <div class="absolute inset-0 bg-[#050b1c]/50" x-show="open" x-transition.opacity @click="open = false"></div>
    <aside x-show="open" x-trap.noscroll="open"
        x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
        x-transition:leave="transition duration-150 ease-in" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
        class="absolute inset-y-0 right-0 flex w-full {{ $width }} flex-col border-l border-line bg-surface shadow-pop">
        <header class="flex items-center justify-between gap-4 border-b border-line px-6 py-4">
            <h2 id="{{ $event }}-title" class="min-w-0 truncate text-base font-semibold text-ink" x-text="title || @js($label)"></h2>
            <div class="flex items-center gap-1">
                <a :href="url || '#'" class="grid size-8 place-items-center rounded-lg text-ink-3 hover:bg-surface-3 hover:text-ink" aria-label="Open as a page"><x-icon name="external-link" class="size-4" /></a>
                <button type="button" @click="open = false" class="grid size-8 place-items-center rounded-lg text-ink-3 hover:bg-surface-3 hover:text-ink" aria-label="Close"><x-icon name="x" class="size-4" /></button>
            </div>
        </header>
        <div class="flex-1 overflow-y-auto px-6 py-5" aria-live="polite">
            <p x-show="loading" class="py-10 text-center text-[13px] text-ink-3">Loading…</p>
            <div x-show="!loading" x-html="html"></div>
        </div>
    </aside>
</div>
