@props([
    'label',
    'value' => null,
    'icon' => 'users',
    'tone' => 'primary', // primary | success | warning | danger | muted
    'hint' => null,
    'hintTone' => null,
    'progress' => null,
    'href' => null,
    'soon' => false,
])
@php
    $tones = [
        'primary' => 'bg-primary-soft text-primary-ink',
        'success' => 'bg-success-soft text-success-ink',
        'warning' => 'bg-warning-soft text-warning-ink',
        'danger' => 'bg-danger-soft text-danger-ink',
        'muted' => 'bg-neutral-soft text-neutral-ink',
    ];
    $bars = ['primary' => 'bg-primary', 'success' => 'bg-success', 'warning' => 'bg-warning', 'danger' => 'bg-danger', 'muted' => 'bg-ink-3'];
    $hintColors = ['success' => 'text-success-ink', 'danger' => 'text-danger-ink', 'warning' => 'text-warning-ink'];
    $tag = $href ? 'a' : 'div';
@endphp
<{{ $tag }} @if ($href) href="{{ $href }}" @endif {{ $attributes->class(['card flex items-start gap-3.5 p-4', 'transition hover:border-primary/40' => $href, 'opacity-80' => $soon]) }}>
    <span class="grid size-10 shrink-0 place-items-center rounded-xl {{ $tones[$soon ? 'muted' : $tone] }}"><x-icon :name="$icon" class="size-5" /></span>
    <span class="min-w-0 flex-1">
        <span class="block truncate text-[12.5px] font-medium text-ink-2">{{ $label }}</span>
        @if ($soon)
            <span class="mt-1 block text-2xl font-bold leading-tight text-ink-3">&mdash;</span>
            <span class="mt-0.5 block text-xs text-ink-3">Coming soon</span>
        @else
            <span class="mt-0.5 block text-[26px] font-bold leading-tight tracking-tight text-ink">{{ $value }}</span>
            @if ($hint)
                <span class="mt-0.5 flex items-center gap-2 text-xs font-medium {{ $hintColors[$hintTone] ?? 'text-ink-3' }}">
                    {{ $hint }}
                    @if ($progress !== null)
                        <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-surface-3"><span class="block h-full rounded-full {{ $bars[$tone] }}" style="width: {{ max(0, min(100, $progress)) }}%"></span></span>
                    @endif
                </span>
            @endif
        @endif
    </span>
</{{ $tag }}>
