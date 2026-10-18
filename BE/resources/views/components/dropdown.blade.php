@props(['align' => 'right', 'width' => 'w-56'])
{{-- The menu is teleported to <body> and anchored to the trigger, so table wrappers with overflow never clip it. --}}
<div x-data="{ open: false }" @keydown.escape.window="open = false" {{ $attributes->class(['relative']) }}>
    <div x-ref="trigger" @click="open = !open" :aria-expanded="open" aria-haspopup="menu">{{ $trigger }}</div>
    <template x-teleport="body">
        <div x-show="open" x-cloak x-anchor.{{ $align === 'right' ? 'bottom-end' : 'bottom-start' }}.offset.6="$refs.trigger"
            @click.outside="open = false" @click="open = false" role="menu"
            class="z-[60] {{ $width }} overflow-hidden rounded-xl border border-line bg-surface p-1.5 shadow-pop">
            {{ $slot }}
        </div>
    </template>
</div>
