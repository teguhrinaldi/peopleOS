@props(['href' => null, 'icon' => null, 'danger' => false, 'type' => 'button', 'dispatch' => null])
{{-- $dispatch: ['name' => modal, 'data' => [...]] opens a modal (see x-modal / x-confirm). --}}
@php
    $class = 'flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-[13px] font-medium transition '.($danger ? 'text-danger-ink hover:bg-danger-soft' : 'text-ink-2 hover:bg-surface-3 hover:text-ink');
    $click = $dispatch ? "\$dispatch('open-modal', ".\Illuminate\Support\Js::from($dispatch).')' : null;
@endphp
@if ($href)
    <a href="{{ $href }}" role="menuitem" {{ $attributes->merge(['class' => $class]) }}>@if ($icon)<x-icon :name="$icon" class="size-4" />@endif{{ $slot }}</a>
@else
    <button type="{{ $type }}" role="menuitem" @if ($click) @click="{{ $click }}" @endif {{ $attributes->merge(['class' => $class]) }}>@if ($icon)<x-icon :name="$icon" class="size-4" />@endif{{ $slot }}</button>
@endif
