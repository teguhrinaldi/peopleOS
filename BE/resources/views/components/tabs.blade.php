@props(['tabs', 'active'])
{{-- $tabs: list of ['key', 'label', 'href', 'icon'?, 'count'?]. Link-based so each tab is server-rendered on demand. --}}
<nav {{ $attributes->class(['no-print flex gap-1 overflow-x-auto rounded-xl border border-line bg-surface-2 p-1']) }} role="tablist">
    @foreach ($tabs as $tab)
        @php $on = $tab['key'] === $active; @endphp
        <a href="{{ $tab['href'] }}" role="tab" aria-selected="{{ $on ? 'true' : 'false' }}"
            class="relative flex items-center gap-2 whitespace-nowrap rounded-lg px-4 py-2 text-[13px] font-semibold transition {{ $on ? 'bg-surface text-primary-ink shadow-sm after:absolute after:inset-x-3 after:-bottom-1 after:h-0.5 after:rounded-full after:bg-primary' : 'text-ink-2 hover:text-ink' }}">
            @isset($tab['icon'])<x-icon :name="$tab['icon']" class="size-4" />@endisset
            {{ $tab['label'] }}
            @isset($tab['count'])<span class="rounded-full bg-surface-3 px-1.5 text-[11px] text-ink-2">{{ $tab['count'] }}</span>@endisset
        </a>
    @endforeach
</nav>
