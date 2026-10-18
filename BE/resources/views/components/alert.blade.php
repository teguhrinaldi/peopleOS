@props(['tone' => 'info', 'title' => null, 'icon' => null])
@php
    $tones = [
        'info' => ['bg-info-soft text-info-ink', 'info'],
        'success' => ['bg-success-soft text-success-ink', 'circle-check'],
        'warning' => ['bg-warning-soft text-warning-ink', 'triangle-alert'],
        'danger' => ['bg-danger-soft text-danger-ink', 'circle-alert'],
    ];
    [$class, $defaultIcon] = $tones[$tone] ?? $tones['info'];
@endphp
<div role="{{ $tone === 'danger' ? 'alert' : 'status' }}" {{ $attributes->class(['flex items-start gap-3 rounded-xl px-4 py-3 text-[13px]', $class]) }}>
    <x-icon :name="$icon ?? $defaultIcon" class="mt-0.5 size-[18px]" />
    <div class="min-w-0 flex-1">
        @if ($title)<p class="font-semibold">{{ $title }}</p>@endif
        <div class="{{ $title ? 'mt-0.5 opacity-90' : '' }}">{{ $slot }}</div>
    </div>
</div>
