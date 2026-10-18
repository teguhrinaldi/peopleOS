@props(['name', 'label', 'checked' => false, 'helper' => null])
@php
    $id = $attributes->get('id', 'f-'.trim(preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
    $isOn = (bool) old($name, $checked);
@endphp
<div {{ $attributes->only('class') }}>
    <label for="{{ $id }}" class="flex cursor-pointer items-center justify-between gap-4">
        <span>
            <span class="block text-[13.5px] font-medium text-ink">{{ $label }}</span>
            @if ($helper)<span class="mt-0.5 block text-xs text-ink-3">{{ $helper }}</span>@endif
        </span>
        <input type="hidden" name="{{ $name }}" value="0">
        <span class="relative shrink-0">
            <input id="{{ $id }}" type="checkbox" name="{{ $name }}" value="1" @checked($isOn) class="peer sr-only" {{ $attributes->except(['class', 'id']) }}>
            <span class="block h-6 w-11 rounded-full bg-line-strong transition peer-checked:bg-primary peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-primary"></span>
            <span class="absolute left-0.5 top-0.5 size-5 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span>
        </span>
    </label>
</div>
