@props(['column', 'label', 'sort' => null, 'dir' => 'asc'])
@php
    $active = $sort === $column;
    $next = $active && $dir === 'asc' ? 'desc' : 'asc';
    $url = request()->fullUrlWithQuery(['sort' => $column, 'dir' => $next, 'page' => null]);
@endphp
<th {{ $attributes }} @if ($active) aria-sort="{{ $dir === 'asc' ? 'ascending' : 'descending' }}" @endif>
    <a href="{{ $url }}" class="inline-flex items-center gap-1 hover:text-ink {{ $active ? 'text-ink' : '' }}">
        {{ $label }}
        <x-icon :name="$active ? ($dir === 'asc' ? 'arrow-up' : 'arrow-down') : 'arrow-up-down'" class="size-3 {{ $active ? 'text-primary' : 'text-ink-3/60' }}" />
    </a>
</th>
