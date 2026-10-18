@props([
    'variant' => 'primary', // primary | secondary | outline | danger | danger-soft | text | navy
    'size' => 'md',         // sm | md | lg
    'href' => null,
    'type' => 'button',
    'icon' => null,
    'iconRight' => null,
    'loading' => false,
    'dispatch' => null,     // ['name' => modal, 'data' => [...]] opens a modal
])
@php
    $classes = "btn btn-{$size} btn-{$variant}".($loading ? ' is-loading' : '');
    $click = $dispatch ? "\$dispatch('open-modal', ".\Illuminate\Support\Js::from($dispatch).')' : null;
@endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<x-icon :name="$icon" class="size-4" />@endif
        {{ $slot }}
        @if ($iconRight)<x-icon :name="$iconRight" class="size-4" />@endif
    </a>
@else
    <button type="{{ $type }}" @if ($click) @click="{{ $click }}" @endif {{ $attributes->merge(['class' => $classes]) }} @disabled($loading)>
        @if ($icon)<x-icon :name="$icon" class="size-4" />@endif
        {{ $slot }}
        @if ($iconRight)<x-icon :name="$iconRight" class="size-4" />@endif
    </button>
@endif
