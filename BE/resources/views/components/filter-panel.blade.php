@props(['action', 'active' => false, 'reset' => null])
{{--
    Filter form. Desktop/tablet: an inline card. Phones (< md): collapsed behind a "Filters" button and
    shown as a bottom sheet. Empty fields are dropped from the URL on submit.
--}}
<div x-data="{ sheet: false }" @keydown.escape.window="sheet = false" class="no-print">
    <div class="flex items-center gap-2 md:hidden">
        <button type="button" class="btn btn-md btn-outline" @click="sheet = true" :aria-expanded="sheet" aria-controls="filters-sheet">
            <x-icon name="filter" class="size-4" /> Filters
            @if ($active)<span class="grid size-5 place-items-center rounded-full bg-primary text-[11px] font-bold text-white" aria-label="Filters active">•</span>@endif
        </button>
        @if ($active && $reset)<a href="{{ $reset }}" class="btn btn-md btn-text">Clear</a>@endif
    </div>

    <div x-show="sheet" x-transition.opacity x-cloak class="fixed inset-0 z-40 bg-[#050b1c]/50 md:hidden" @click="sheet = false" aria-hidden="true"></div>

    <form id="filters-sheet" method="GET" action="{{ $action }}"
        @submit="[...$el.elements].forEach(f => { if (f.name && f.value === '') f.disabled = true })"
        :class="sheet ? 'max-md:!fixed max-md:!flex' : ''"
        {{ $attributes->class([
            'card hidden grid-cols-2 gap-3 p-4 md:grid md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-[repeat(auto-fit,minmax(150px,1fr))]',
            'max-md:inset-x-0 max-md:bottom-0 max-md:z-50 max-md:max-h-[85vh] max-md:flex-col max-md:overflow-y-auto max-md:rounded-b-none max-md:shadow-pop',
        ]) }}>
        <div class="flex items-center justify-between md:hidden">
            <h2 class="text-base font-semibold text-ink">Filters</h2>
            <button type="button" @click="sheet = false" class="grid size-8 place-items-center rounded-lg text-ink-3 hover:bg-surface-3" aria-label="Close filters"><x-icon name="x" class="size-4" /></button>
        </div>
        {{ $slot }}
        <div class="flex items-end gap-2 max-md:mt-2">
            <x-button type="submit" icon="filter" class="max-md:flex-1">Apply</x-button>
            @if ($active && $reset)<x-button variant="text" :href="$reset">Clear</x-button>@endif
        </div>
    </form>
</div>
