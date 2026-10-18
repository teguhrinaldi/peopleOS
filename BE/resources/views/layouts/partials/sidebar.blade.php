{{-- Locked grouped IA. $rail = true on the desktop/tablet aside (icon-rail on tablet widths); false inside the mobile drawer. --}}
@php $hide = $rail ? 'rail-hide' : ''; @endphp
<div class="flex h-full flex-col bg-gradient-to-b from-[var(--nav-from)] to-[var(--nav-to)]">
    <div class="flex h-16 shrink-0 items-center justify-between px-5 {{ $rail ? 'rail-center' : '' }}">
        <a href="{{ route('admin.dashboard') }}" aria-label="PeopleOS dashboard" class="{{ $rail ? 'rail-hide' : '' }}"><x-logo light /></a>
        @if ($rail)<a href="{{ route('admin.dashboard') }}" class="hidden rail-mark" aria-label="PeopleOS dashboard"><x-logo mark /></a>@endif
        @unless ($rail)
            <button type="button" @click="drawer = false" class="grid size-8 place-items-center rounded-lg text-[var(--nav-text)] hover:bg-[var(--nav-hover)]" aria-label="Close menu"><x-icon name="x" class="size-4" /></button>
        @endunless
    </div>

    <nav class="flex-1 space-y-0.5 overflow-y-auto px-3 pb-6" aria-label="Main navigation">
        @foreach ($nav as $entry)
            @if ($entry['type'] === 'link')
                <a href="{{ route($entry['route']) }}" @if ($entry['active']) aria-current="page" @endif title="{{ $entry['label'] }}" class="nav-link {{ $rail ? 'rail-center' : '' }}">
                    <x-icon :name="$entry['icon']" class="size-[18px]" />
                    <span class="{{ $hide }}">{{ $entry['label'] }}</span>
                </a>
            @else
                <div class="mt-1.5 border-t border-[var(--nav-line)] pt-1.5">
                    <button type="button" @click="toggle('{{ $entry['key'] }}')" :aria-expanded="isOpen('{{ $entry['key'] }}')"
                        class="{{ $hide }} flex w-full items-center justify-between rounded-lg px-3 py-1 text-[12.5px] font-semibold {{ $entry['active'] ? 'text-white' : 'text-[var(--nav-muted)]' }} hover:text-white">
                        <span>{{ $entry['label'] }}</span>
                        <x-icon name="chevron-down" class="size-3.5 transition-transform" ::class="isOpen('{{ $entry['key'] }}') ? '' : '-rotate-90'" />
                    </button>
                    <div x-show="isOpen('{{ $entry['key'] }}')" class="nav-sub mt-0.5 space-y-0.5 rail-show" @unless ($rail) style="padding-left:6px" @endunless>
                        @foreach ($entry['items'] as $item)
                            <a href="{{ route($item['route']) }}" @if ($item['active']) aria-current="page" @endif title="{{ $item['label'] }}" class="nav-link {{ $rail ? 'rail-center' : '' }}">
                                <x-icon :name="$item['icon']" class="size-[17px]" />
                                <span class="{{ $hide }}">{{ $item['label'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        @endforeach
    </nav>
</div>
