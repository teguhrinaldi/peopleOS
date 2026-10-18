@props(['name', 'label', 'checked' => false, 'helper' => null, 'value' => '1'])
@php
    $id = $attributes->get('id', 'f-'.trim(preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
    // The hidden "0" makes old() distinguish "unchecked" from "field absent" after a validation error.
    $isChecked = (bool) old($name, $checked);
@endphp
<div {{ $attributes->only('class') }}>
    <label for="{{ $id }}" class="flex cursor-pointer items-start gap-2.5">
        <input type="hidden" name="{{ $name }}" value="0">
        <input id="{{ $id }}" type="checkbox" name="{{ $name }}" value="{{ $value }}" @checked($isChecked)
            {{ $attributes->except(['class', 'id'])->merge(['class' => 'mt-0.5 size-4 shrink-0 cursor-pointer rounded border-line-strong accent-[var(--primary)]']) }}>
        <span>
            <span class="text-[13.5px] font-medium text-ink">{{ $label }}</span>
            @if ($helper)<span class="mt-0.5 block text-xs text-ink-3">{{ $helper }}</span>@endif
        </span>
    </label>
</div>
