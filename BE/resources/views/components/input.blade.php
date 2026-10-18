@props(['name', 'label' => null, 'type' => 'text', 'value' => null, 'helper' => null, 'required' => false, 'icon' => null])
@php
    $id = $attributes->get('id', 'f-'.trim(preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
    $dotted = trim(str_replace(['[', ']'], ['.', ''], $name), '.');
    $error = $errors->first($dotted);
    $current = $type === 'password' ? '' : old($dotted, $value);
@endphp
<div {{ $attributes->only('class') }}>
    @if ($label)
        <label for="{{ $id }}" class="mb-1.5 block text-[13px] font-medium text-ink">{{ $label }}@if ($required)<span class="text-danger"> *</span>@endif</label>
    @endif
    <div class="relative">
        @if ($icon)<x-icon :name="$icon" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-ink-3" />@endif
        <input id="{{ $id }}" type="{{ $type }}" name="{{ $name }}" value="{{ $current }}"
            {{ $attributes->except(['class', 'id'])->merge(['class' => 'ctl'.($icon ? ' pl-9' : '')]) }}
            @required($required)
            @if ($error) aria-invalid="true" aria-describedby="{{ $id }}-msg" @elseif ($helper) aria-describedby="{{ $id }}-msg" @endif>
    </div>
    @if ($error)
        <p id="{{ $id }}-msg" class="mt-1.5 text-xs font-medium text-danger-ink">{{ $error }}</p>
    @elseif ($helper)
        <p id="{{ $id }}-msg" class="mt-1.5 text-xs text-ink-3">{{ $helper }}</p>
    @endif
</div>
