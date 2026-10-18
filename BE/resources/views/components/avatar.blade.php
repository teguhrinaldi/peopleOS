@props(['name' => '', 'src' => null, 'size' => 'md'])
@php
    $sizes = ['xs' => 'size-6 text-[10px]', 'sm' => 'size-8 text-[11px]', 'md' => 'size-10 text-sm', 'lg' => 'size-14 text-lg', 'xl' => 'size-20 text-2xl sm:size-24 sm:text-3xl'];
    $palette = [
        'bg-primary-soft text-primary-ink', 'bg-success-soft text-success-ink', 'bg-warning-soft text-warning-ink',
        'bg-info-soft text-info-ink', 'bg-danger-soft text-danger-ink', 'bg-neutral-soft text-neutral-ink',
    ];
    $initials = \Illuminate\Support\Str::of($name)->trim()->explode(' ')->filter()->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('');
    $tone = $palette[crc32($name) % count($palette)];
    $round = $size === 'xl' ? 'rounded-2xl' : 'rounded-full';
    $url = $src ? asset('storage/'.ltrim($src, '/')) : null;
@endphp
@if ($url)
    <img src="{{ $url }}" alt="{{ $name }}" loading="lazy" {{ $attributes->class(['shrink-0 object-cover ring-1 ring-line', $round, '', $sizes[$size] ?? $sizes['md']]) }}>
@else
    <span role="img" aria-label="{{ $name }}" {{ $attributes->class(['grid shrink-0 place-items-center font-semibold uppercase', $round, '', $tone, $sizes[$size] ?? $sizes['md']]) }}>{{ $initials ?: '?' }}</span>
@endif
