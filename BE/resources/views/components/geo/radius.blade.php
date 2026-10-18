@props(['radius' => 100, 'points' => [], 'live' => false, 'size' => 260])
{{--
    Schematic geofence (no map tiles, no SDK): the circle is the allowed radius, dots are recorded
    positions in metres from the centre (east = right, north = up). With `live`, the radius label and
    scale follow an Alpine `radius` value in the parent scope (used by the location form).
--}}
@php
    $radius = max(1, (int) $radius);
    $far = collect($points)->map(fn ($p) => sqrt($p['x'] ** 2 + $p['y'] ** 2))->max() ?? 0;
    $extent = max($radius * 1.6, $far * 1.2, 20); // metres from centre to the edge of the drawing
    $px = 100 / $extent; // viewBox units per metre (viewBox is 200 wide, centre at 100)
    $r = $radius * $px;
    $dots = collect($points)->groupBy(fn ($p) => round($p['x'] * $px / 4).':'.round($p['y'] * $px / 4))->map(fn ($g) => [
        'x' => $g->avg('x'), 'y' => $g->avg('y'), 'inside' => $g->contains('inside', false) ? false : $g->first()['inside'],
        'label' => $g->pluck('label')->unique()->join(' / '),
    ])->values();
    $scaleMetres = (function (float $extent): int {
        foreach ([5, 10, 20, 25, 50, 100, 200, 250, 500, 1000, 2000, 5000] as $step) {
            if ($step >= $extent / 3) {
                return $step;
            }
        }

        return 10000;
    })($extent);
@endphp
<figure {{ $attributes->class(['relative']) }}>
    <svg viewBox="0 0 200 200" width="{{ $size }}" height="{{ $size }}" class="mx-auto max-w-full" role="img"
        aria-label="Geofence of {{ $radius }} metres{{ $points ? ' with '.count($points).' recorded positions' : '' }}">
        <defs>
            <pattern id="geo-grid" width="20" height="20" patternUnits="userSpaceOnUse"><path d="M20 0H0V20" fill="none" stroke="var(--line)" stroke-width="0.6"/></pattern>
        </defs>
        <rect width="200" height="200" rx="14" fill="var(--surface-2)"/>
        <rect width="200" height="200" rx="14" fill="url(#geo-grid)"/>
        <line x1="100" y1="6" x2="100" y2="194" stroke="var(--line-strong)" stroke-width="0.6" stroke-dasharray="2 3"/>
        <line x1="6" y1="100" x2="194" y2="100" stroke="var(--line-strong)" stroke-width="0.6" stroke-dasharray="2 3"/>
        <text x="100" y="14" text-anchor="middle" font-size="8" font-weight="700" fill="var(--ink-3)">N</text>

        @if ($live)
            <circle cx="100" cy="100" r="62.5" fill="var(--primary)" fill-opacity="0.12" stroke="var(--primary)" stroke-width="1.4"/>
        @else
            <circle cx="100" cy="100" r="{{ round($r, 2) }}" fill="var(--primary)" fill-opacity="0.12" stroke="var(--primary)" stroke-width="1.4"/>
        @endif

        {{-- Centre of the geofence: a small crosshair so recorded dots near it stay visible. --}}
        <circle cx="100" cy="100" r="3" fill="var(--nav-from)" stroke="var(--surface)" stroke-width="1.2"><title>Location centre</title></circle>
        <line x1="92" y1="100" x2="96" y2="100" stroke="var(--nav-from)" stroke-width="1.2"/><line x1="104" y1="100" x2="108" y2="100" stroke="var(--nav-from)" stroke-width="1.2"/>
        <line x1="100" y1="92" x2="100" y2="96" stroke="var(--nav-from)" stroke-width="1.2"/><line x1="100" y1="104" x2="100" y2="108" stroke="var(--nav-from)" stroke-width="1.2"/>

        @foreach ($dots as $p)
            @php $cx = 100 + $p['x'] * $px; $cy = 100 - $p['y'] * $px; $ok = $p['inside'] ?? null; @endphp
            <circle cx="{{ round($cx, 2) }}" cy="{{ round($cy, 2) }}" r="4.2" fill="{{ $ok === false ? 'var(--danger)' : 'var(--success)' }}" stroke="var(--surface)" stroke-width="1.5">
                <title>{{ $p['label'] }}: {{ round(sqrt($p['x'] ** 2 + $p['y'] ** 2)) }} m from centre</title>
            </circle>
            <text x="{{ round($cx + 7, 2) }}" y="{{ round($cy + 3, 2) }}" font-size="8" font-weight="700" fill="var(--ink)" paint-order="stroke" stroke="var(--surface-2)" stroke-width="2.5">{{ $p['label'] }}</text>
        @endforeach


        @unless ($live)
            <g transform="translate(12 184)">
                <line x1="0" y1="0" x2="{{ round($scaleMetres * $px, 2) }}" y2="0" stroke="var(--ink-2)" stroke-width="1.4"/>
                <line x1="0" y1="-3" x2="0" y2="3" stroke="var(--ink-2)" stroke-width="1.2"/>
                <line x1="{{ round($scaleMetres * $px, 2) }}" y1="-3" x2="{{ round($scaleMetres * $px, 2) }}" y2="3" stroke="var(--ink-2)" stroke-width="1.2"/>
                <text x="{{ round($scaleMetres * $px / 2, 2) }}" y="-5" text-anchor="middle" font-size="7.5" fill="var(--ink-2)">{{ $scaleMetres }} m</text>
            </g>
        @endunless
    </svg>
    <figcaption class="mt-2 text-center text-xs text-ink-3">
        @if ($live)
            Allowed radius <span class="font-semibold text-ink" x-text="(parseInt(radius) || 0) + ' m'"></span> · schematic, not to map scale
        @else
            Allowed radius <span class="font-semibold text-ink">{{ $radius }} m</span>@if ($points) · <span class="text-success-ink">●</span> inside <span class="text-danger-ink">●</span> outside @endif
        @endif
    </figcaption>
</figure>
