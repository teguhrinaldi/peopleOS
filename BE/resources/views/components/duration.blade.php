@props(['minutes' => null])
{{-- 486 -> "8h 06m"; null or 0 -> em dash. --}}
@if ($minutes)<span {{ $attributes->class(['tabular-nums']) }}>{{ intdiv((int) $minutes, 60) }}h {{ str_pad((string) ((int) $minutes % 60), 2, '0', STR_PAD_LEFT) }}m</span>@else<span class="text-ink-3">—</span>@endif
