@props(['show', 'side' => 'left', 'label' => 'Menu', 'width' => 'w-[288px]'])
{{-- $show is the name of a boolean in the parent Alpine scope. --}}
<div x-show="{{ $show }}" x-cloak class="fixed inset-0 z-50 md:hidden no-print" role="dialog" aria-modal="true" aria-label="{{ $label }}" @keydown.escape.window="{{ $show }} = false">
    <div class="absolute inset-0 bg-[#050b1c]/60" x-show="{{ $show }}" x-transition.opacity @click="{{ $show }} = false"></div>
    <div x-show="{{ $show }}" x-trap.noscroll="{{ $show }}"
        x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="{{ $side === 'left' ? '-translate-x-full' : 'translate-x-full' }}" x-transition:enter-end="translate-x-0"
        x-transition:leave="transition duration-150 ease-in" x-transition:leave-start="translate-x-0" x-transition:leave-end="{{ $side === 'left' ? '-translate-x-full' : 'translate-x-full' }}"
        {{ $attributes->class(['absolute inset-y-0 flex max-w-[86vw] flex-col shadow-pop', $width, $side === 'left' ? 'left-0' : 'right-0']) }}>
        {{ $slot }}
    </div>
</div>
