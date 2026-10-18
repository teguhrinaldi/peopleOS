@props(['start', 'end', 'overnight' => null])
{{-- "22:00 → 06:00 +1": an overnight shift is one span that ends the next day. --}}
@php
    $s = substr((string) $start, 0, 5);
    $e = substr((string) $end, 0, 5);
    $overnight ??= $e <= $s;
@endphp
<span {{ $attributes->class(['inline-flex items-center gap-1.5 whitespace-nowrap tabular-nums']) }}>
    <span class="font-semibold text-ink">{{ $s }}</span>
    <span class="text-ink-3">→</span>
    <span class="font-semibold text-ink">{{ $e }}</span>
    @if ($overnight)<span class="rounded-md bg-info-soft px-1.5 py-px text-[11px] font-bold text-info-ink" title="Ends the next day">+1</span>@endif
</span>
