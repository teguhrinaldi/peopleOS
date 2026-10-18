@props(['days', 'meta'])
{{--
    Attendance trend: stacked bars per day (On time + Late = Present, plus Absent on closed working days).
    Numbers come from App\Services\Admin\AttendanceMetrics (the same definitions as the KPI cards).
    - rest days / holidays are "no data" (light band), never a zero bar; a working day with nobody present is a real zero
    - late = diagonal stripes, absent = dots, so the series differ without colour
    - every bar is keyboard focusable and shows an exact tooltip; the same numbers are in a table (toggle) and the aria-label
--}}
@php
    $uid = 'tr'.substr(md5(json_encode(array_column($days, 'date'))), 0, 6);
    $n = count($days);
    $W = 640; $H = 270; $pl = 40; $pr = 12; $pt = 22; $pb = 40;
    $plotW = $W - $pl - $pr; $plotH = $H - $pt - $pb;
    $stack = fn ($d) => (int) $d['present'] + (int) ($d['absent'] ?? 0);
    $max = max(1, ...array_map($stack, $days ?: [['present' => 0]]));
    $step = collect([1, 2, 5, 10, 20, 25, 50, 100, 200, 500])->first(fn ($s) => $max / $s <= 5) ?? 1000;
    $top = (int) (ceil($max / $step) * $step);
    $ticks = range(0, $top, $step);
    $slot = $plotW / max(1, $n);
    $barW = max(8, min(46, $slot * 0.58));
    $y = fn ($v) => $pt + $plotH - ($v / $top * $plotH);
    $labelEvery = $n <= 8 ? 1 : ($n <= 16 ? 2 : 5);
    $totals = $meta['totals'];
    $period = \App\Support\DateDisplay::range($meta['from'], $meta['to']);
    $describe = function ($d) {
        if (! $d['working'] && $d['present'] === 0) {
            return $d['dayLabel'].': rest day or holiday, no attendance expected';
        }
        $text = $d['dayLabel'].': '.$d['present'].' present ('.$d['ontime'].' on time, '.$d['late'].' late)';
        if ($d['absent'] !== null) {
            $text .= ', '.$d['absent'].' absent';
        }
        if ($d['notYet'] !== null) {
            $text .= ', '.$d['notYet'].' not checked in yet';
        }

        return $text.($d['today'] ? ' (today, still in progress)' : '');
    };
    $summary = 'Attendance trend, '.$period.': '.$totals['present'].' check-ins ('.$totals['ontime'].' on time, '.$totals['late'].' late) and '.$totals['absent'].' absences over '.$totals['workingDays'].' working days.';
@endphp

<p class="mb-3 text-[12.5px] text-ink-3">{{ $period }} · company time ({{ $meta['timezone'] }}) · On time + Late = Present</p>
@if ($meta['error'])
    <div class="flex flex-col items-center gap-3 rounded-xl bg-danger-soft px-6 py-12 text-center" role="alert">
        <x-icon name="circle-alert" class="size-6 text-danger-ink" />
        <p class="text-[14px] font-semibold text-danger-ink">The attendance trend could not be loaded</p>
        <p class="max-w-sm text-[13px] text-ink-2">Nothing is shown rather than made-up numbers. The error was logged.</p>
        <button type="button" class="btn btn-md btn-outline" @click="$dispatch('trend-retry')">Try again</button>
    </div>
@elseif (! $meta['hasData'])
    <div class="flex flex-col items-center gap-2 rounded-xl bg-surface-2 px-6 py-12 text-center">
        <x-icon name="chart-column" class="size-6 text-ink-3" />
        <p class="text-[14px] font-semibold text-ink">No attendance data for {{ $period }}</p>
        <p class="max-w-sm text-[13px] text-ink-3">Nobody was scheduled and nobody checked in during these days. Pick a longer range or check the shift assignments.</p>
    </div>
@else
    <figure x-data="{ tip: null, table: false }" @mouseleave="tip = null">
        <div class="overflow-x-auto"><div class="relative min-w-[30rem]">
        <svg viewBox="0 0 {{ $W }} {{ $H }}" class="block h-auto w-full" role="img" aria-label="{{ $summary }}" preserveAspectRatio="xMidYMid meet">
            <defs>
                <pattern id="{{ $uid }}-late" width="6" height="6" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
                    <rect width="6" height="6" fill="var(--warning)" /><rect width="2.4" height="6" fill="rgb(255 255 255 / 0.55)" />
                </pattern>
                <pattern id="{{ $uid }}-absent" width="5" height="5" patternUnits="userSpaceOnUse">
                    <rect width="5" height="5" fill="var(--danger-soft)" /><circle cx="2.5" cy="2.5" r="1.1" fill="var(--danger)" />
                </pattern>
            </defs>

            @foreach ($ticks as $tick)
                <line x1="{{ $pl }}" x2="{{ $W - $pr }}" y1="{{ $y($tick) }}" y2="{{ $y($tick) }}" stroke="var(--line)" stroke-width="1" @if ($tick > 0) stroke-dasharray="3 4" @endif />
                <text x="{{ $pl - 8 }}" y="{{ $y($tick) + 4 }}" text-anchor="end" font-size="11" fill="var(--ink-3)">{{ $tick }}</text>
            @endforeach

            @foreach ($days as $i => $d)
                @php
                    $cx = $pl + $slot * $i + $slot / 2;
                    $x = $cx - $barW / 2;
                    $rest = ! $d['working'] && $d['present'] === 0;
                    $hOn = $d['ontime'] / $top * $plotH;
                    $hLate = $d['late'] / $top * $plotH;
                    $hAbs = ((int) ($d['absent'] ?? 0)) / $top * $plotH;
                    $base = $pt + $plotH;
                    $totalH = $hOn + $hLate + $hAbs;
                @endphp
                @if ($rest)
                    <rect x="{{ $cx - $slot / 2 + 3 }}" y="{{ $pt }}" width="{{ $slot - 6 }}" height="{{ $plotH }}" rx="6" fill="var(--surface-2)" />
                    <text x="{{ $cx }}" y="{{ $pt + $plotH / 2 }}" text-anchor="middle" font-size="10.5" fill="var(--ink-3)" transform="rotate(-90 {{ $cx }} {{ $pt + $plotH / 2 }})">{{ $n <= 14 ? 'Rest day' : '' }}</text>
                @endif
                @if ($d['ontime'] > 0)<rect x="{{ $x }}" y="{{ $base - $hOn }}" width="{{ $barW }}" height="{{ $hOn }}" fill="var(--primary)" rx="{{ $hLate + $hAbs > 0 ? 0 : 4 }}" />@endif
                @if ($d['late'] > 0)<rect x="{{ $x }}" y="{{ $base - $hOn - $hLate }}" width="{{ $barW }}" height="{{ $hLate }}" fill="url(#{{ $uid }}-late)" stroke="var(--warning)" stroke-width="1" rx="{{ $hAbs > 0 ? 0 : 4 }}" />@endif
                @if ($hAbs > 0)<rect x="{{ $x }}" y="{{ $base - $totalH }}" width="{{ $barW }}" height="{{ $hAbs }}" fill="url(#{{ $uid }}-absent)" stroke="var(--danger)" stroke-width="1" stroke-dasharray="3 2" rx="4" />@endif
                @if (! $rest && $n <= 16)
                    <text x="{{ $cx }}" y="{{ $base - $totalH - 5 }}" text-anchor="middle" font-size="11" font-weight="600" fill="var(--ink-2)">{{ $d['present'] }}</text>
                @endif
                @if ($i % $labelEvery === 0 || $d['today'])
                    <text x="{{ $cx }}" y="{{ $H - 20 }}" text-anchor="middle" font-size="11.5" fill="{{ $d['today'] ? 'var(--ink)' : 'var(--ink-3)' }}" @if ($d['today']) font-weight="700" @endif>{{ $n <= 8 ? $d['label'] : \Illuminate\Support\Carbon::parse($d['date'])->format('j') }}</text>
                    <text x="{{ $cx }}" y="{{ $H - 6 }}" text-anchor="middle" font-size="10" fill="var(--ink-3)">{{ $d['today'] ? 'Today' : ($n <= 8 ? \Illuminate\Support\Carbon::parse($d['date'])->format('j M') : \Illuminate\Support\Carbon::parse($d['date'])->format('D')) }}</text>
                @endif
                <rect x="{{ $cx - $slot / 2 }}" y="{{ $pt }}" width="{{ $slot }}" height="{{ $plotH + 22 }}" fill="transparent" tabindex="0" role="img" aria-label="{{ $describe($d) }}"
                    class="trend-hit" @mouseenter="tip = {{ $i }}" @focus="tip = {{ $i }}" @blur="tip = null" />
            @endforeach
        </svg>

        @foreach ($days as $i => $d)
            @php
                $pct = (($pl + $slot * $i + $slot / 2) / $W) * 100;
                $rest = ! $d['working'] && $d['present'] === 0;
            @endphp
            <div x-show="tip === {{ $i }}" x-cloak role="tooltip" aria-hidden="true"
                class="pointer-events-none absolute top-1 z-10 w-48 rounded-xl border border-line bg-surface p-3 text-xs shadow-pop"
                style="left: clamp(0px, calc({{ number_format($pct, 2, '.', '') }}% - 96px), calc(100% - 192px))">
                <p class="font-semibold text-ink">{{ \Illuminate\Support\Carbon::parse($d['date'])->format('l, j F Y') }}@if ($d['today']) <span class="font-normal text-ink-3">(today)</span>@endif</p>
                @if ($rest)
                    <p class="mt-1 text-ink-3">Rest day or holiday: nobody is expected, so there is no data.</p>
                @else
                    <dl class="mt-1.5 space-y-1 text-ink-2">
                        <div class="flex justify-between"><dt class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-primary"></span>On time</dt><dd class="font-semibold tabular-nums text-ink">{{ $d['ontime'] }}</dd></div>
                        <div class="flex justify-between"><dt class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-warning"></span>Late</dt><dd class="font-semibold tabular-nums text-ink">{{ $d['late'] }}</dd></div>
                        <div class="flex justify-between border-t border-line pt-1"><dt>Present</dt><dd class="font-semibold tabular-nums text-ink">{{ $d['present'] }}</dd></div>
                        @if ($d['absent'] !== null)<div class="flex justify-between"><dt class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-danger"></span>Absent</dt><dd class="font-semibold tabular-nums text-ink">{{ $d['absent'] }}</dd></div>@endif
                        @if ($d['notYet'] !== null)<div class="flex justify-between"><dt>Not checked in yet</dt><dd class="font-semibold tabular-nums text-ink">{{ $d['notYet'] }}</dd></div>@endif
                        <div class="flex justify-between text-ink-3"><dt>Expected</dt><dd class="tabular-nums">{{ $d['expected'] }}</dd></div>
                    </dl>
                @endif
            </div>
        @endforeach
        </div></div>

        <figcaption class="mt-2 flex flex-wrap items-center justify-between gap-x-5 gap-y-2 text-xs text-ink-2">
            <span class="flex flex-wrap items-center gap-x-4 gap-y-1">
                <span class="inline-flex items-center gap-1.5"><svg width="12" height="12" aria-hidden="true"><rect width="12" height="12" rx="2" fill="var(--primary)" /></svg>On time <b class="tabular-nums text-ink">{{ $totals['ontime'] }}</b></span>
                <span class="inline-flex items-center gap-1.5"><svg width="12" height="12" aria-hidden="true"><defs><pattern id="{{ $uid }}-lg" width="6" height="6" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><rect width="6" height="6" fill="var(--warning)" /><rect width="2.4" height="6" fill="rgb(255 255 255 / 0.55)" /></pattern></defs><rect width="12" height="12" rx="2" fill="url(#{{ $uid }}-lg)" stroke="var(--warning)" /></svg>Late <b class="tabular-nums text-ink">{{ $totals['late'] }}</b></span>
                <span class="inline-flex items-center gap-1.5"><svg width="12" height="12" aria-hidden="true"><defs><pattern id="{{ $uid }}-ag" width="5" height="5" patternUnits="userSpaceOnUse"><rect width="5" height="5" fill="var(--danger-soft)" /><circle cx="2.5" cy="2.5" r="1.1" fill="var(--danger)" /></pattern></defs><rect width="12" height="12" rx="2" fill="url(#{{ $uid }}-ag)" stroke="var(--danger)" stroke-dasharray="3 2" /></svg>Absent <b class="tabular-nums text-ink">{{ $totals['absent'] }}</b></span>
                <span class="inline-flex items-center gap-1.5"><svg width="12" height="12" aria-hidden="true"><rect width="12" height="12" rx="2" fill="var(--surface-2)" stroke="var(--line)" /></svg>No data (rest day)</span>
            </span>
            <button type="button" class="font-semibold text-primary-ink hover:underline" @click="table = !table" :aria-expanded="table" x-text="table ? 'Hide table' : 'Show as table'">Show as table</button>
        </figcaption>

        <div x-show="table" x-cloak class="mt-3 overflow-x-auto rounded-xl border border-line">
            <table class="dt text-xs">
                <caption class="sr-only">{{ $summary }}</caption>
                <thead><tr><th scope="col">Date</th><th scope="col" class="text-right">Present</th><th scope="col" class="text-right">On time</th><th scope="col" class="text-right">Late</th><th scope="col" class="text-right">Absent</th><th scope="col" class="text-right">Expected</th></tr></thead>
                <tbody>
                    @foreach (array_reverse($days) as $d)
                        <tr><th scope="row" class="text-left font-medium">{{ $d['dayLabel'] }}@if ($d['today']) (today)@endif</th>
                            @if (! $d['working'] && $d['present'] === 0)
                                <td colspan="5" class="text-ink-3">Rest day or holiday: no data</td>
                            @else
                                <td class="text-right tabular-nums">{{ $d['present'] }}</td><td class="text-right tabular-nums">{{ $d['ontime'] }}</td><td class="text-right tabular-nums">{{ $d['late'] }}</td>
                                <td class="text-right tabular-nums">{{ $d['absent'] ?? '—' }}</td><td class="text-right tabular-nums">{{ $d['expected'] }}</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </figure>
@endif
