@props(['action', 'reset' => null, 'q' => null, 'placeholder' => 'Search', 'active' => false])
{{--
    GET search + filters. Selects submit on change and the search box on Enter; sort/direction are preserved and
    empty fields are dropped from the URL. On phones (< md) the filter selects collapse into a bottom sheet.
--}}
@php $hasFilters = trim((string) $slot) !== ''; @endphp
<form method="GET" action="{{ $action }}" role="search" x-data="{ sheet: false }" @keydown.escape.window="sheet = false" {{ $attributes->class(['no-print']) }}
    @submit="[...$el.elements].forEach(field => { if (field.name && field.value === '') field.disabled = true })">
    @foreach (array_filter(request()->only(['sort', 'dir'])) as $key => $val)<input type="hidden" name="{{ $key }}" value="{{ $val }}">@endforeach
    <div class="flex flex-wrap items-center gap-3" @change="if (window.matchMedia('(min-width: 768px)').matches) $el.closest('form').requestSubmit()">
        <div class="relative w-full min-w-[200px] flex-1 sm:w-80 sm:flex-none">
            <x-icon name="search" class="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-ink-3" />
            <input type="search" name="q" value="{{ $q }}" placeholder="{{ $placeholder }}" class="ctl pl-10" aria-label="{{ $placeholder }}">
        </div>
        @if ($hasFilters)
            <button type="button" class="btn btn-md btn-outline md:hidden" @click="sheet = true" :aria-expanded="sheet">
                <x-icon name="filter" class="size-4" /> Filters
                @if ($active)<span class="grid size-5 place-items-center rounded-full bg-primary text-[11px] font-bold text-white" aria-label="Filters active">•</span>@endif
            </button>
            <div x-show="sheet" x-transition.opacity x-cloak class="fixed inset-0 z-40 bg-[#050b1c]/50 md:hidden" @click="sheet = false" aria-hidden="true"></div>
            <div :class="sheet ? 'max-md:flex' : 'max-md:hidden'"
                class="flex flex-wrap items-center gap-3 max-md:fixed max-md:inset-x-0 max-md:bottom-0 max-md:z-50 max-md:max-h-[85vh] max-md:flex-col max-md:items-stretch max-md:overflow-y-auto max-md:rounded-t-2xl max-md:border max-md:border-line max-md:bg-surface max-md:p-4 max-md:shadow-pop max-md:[&>*]:!w-full">
                <div class="flex items-center justify-between md:hidden">
                    <span class="text-base font-semibold text-ink">Filters</span>
                    <button type="button" @click="sheet = false" class="grid size-8 place-items-center rounded-lg text-ink-3 hover:bg-surface-3" aria-label="Close filters"><x-icon name="x" class="size-4" /></button>
                </div>
                {{ $slot }}
                <x-button type="submit" class="md:hidden">Apply filters</x-button>
            </div>
        @endif
        @if ($active && $reset)<a href="{{ $reset }}" class="inline-flex items-center gap-1 text-[13px] font-semibold text-primary-ink hover:underline"><x-icon name="x" class="size-3.5" />Clear filters</a>@endif
    </div>
</form>
