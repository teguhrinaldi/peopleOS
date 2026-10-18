@props(['segments', 'total' => null, 'label' => 'Total', 'size' => 168])
{{-- Plain SVG donut. $segments: list of ['label', 'value', 'color' => CSS colour/var()]. --}}
@php
    $sum = collect($segments)->sum('value');
    $total ??= $sum;
    $offset = 25; // start at 12 o'clock
    $circle = 15.9155;
@endphp
<div {{ $attributes->class(['flex flex-wrap items-center gap-x-8 gap-y-5']) }}>
    <div class="relative shrink-0" style="width: {{ $size }}px; height: {{ $size }}px">
        <svg viewBox="0 0 42 42" class="size-full" role="img" aria-label="{{ $label }}: {{ $total }}">
            <circle cx="21" cy="21" r="{{ $circle }}" fill="none" stroke="var(--surface-3)" stroke-width="5"/>
            @if ($sum > 0)
                @foreach ($segments as $segment)
                    @php $pct = $segment['value'] / $sum * 100; @endphp
                    @if ($pct > 0)
                        <circle cx="21" cy="21" r="{{ $circle }}" fill="none" stroke="{{ $segment['color'] }}" stroke-width="5"
                            stroke-dasharray="{{ round(max($pct - ($pct < 100 ? 0.6 : 0), 0.01), 3) }} {{ round(100 - max($pct - ($pct < 100 ? 0.6 : 0), 0.01), 3) }}"
                            stroke-dashoffset="{{ round($offset, 3) }}"><title>{{ $segment['label'] }}: {{ $segment['value'] }}</title></circle>
                        @php $offset -= $pct; @endphp
                    @endif
                @endforeach
            @endif
        </svg>
        <div class="absolute inset-0 grid place-content-center text-center">
            <span class="text-[28px] font-bold leading-none tracking-tight text-ink">{{ $total }}</span>
            <span class="mt-1 text-xs text-ink-3">{{ $label }}</span>
        </div>
    </div>
    <ul class="min-w-[150px] flex-1 space-y-2.5 text-[13px]">
        @foreach ($segments as $segment)
            <li class="flex items-center gap-2.5">
                <span class="size-2.5 rounded-full" style="background: {{ $segment['color'] }}"></span>
                <span class="w-7 font-semibold tabular-nums text-ink">{{ $segment['value'] }}</span>
                <span class="text-ink-2">{{ $segment['label'] }}</span>
            </li>
        @endforeach
    </ul>
</div>
