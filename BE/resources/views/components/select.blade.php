@props(['name', 'label' => null, 'options' => [], 'value' => null, 'placeholder' => null, 'helper' => null, 'required' => false])
@php
    $id = $attributes->get('id', 'f-'.trim(preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
    $dotted = trim(str_replace(['[', ']'], ['.', ''], $name), '.');
    $error = $errors->first($dotted);
    $current = (string) old($dotted, $value);
@endphp
<div {{ $attributes->only('class') }}>
    @if ($label)
        <label for="{{ $id }}" class="mb-1.5 block text-[13px] font-medium text-ink">{{ $label }}@if ($required)<span class="text-danger"> *</span>@endif</label>
    @endif
    <x-listbox><select id="{{ $id }}" name="{{ $name }}" {{ $attributes->except(['class', 'id'])->merge(['class' => 'ctl']) }} @required($required) @if ($error) aria-invalid="true" aria-describedby="{{ $id }}-msg" @endif>
        @if ($placeholder !== null)<option value="">{{ $placeholder }}</option>@endif
        @foreach ($options as $optionValue => $optionLabel)
            <option value="{{ $optionValue }}" @selected($current !== '' && (string) $optionValue === $current)>{{ $optionLabel }}</option>
        @endforeach
        {{ $slot }}
    </select></x-listbox>
    @if ($error)
        <p id="{{ $id }}-msg" class="mt-1.5 text-xs font-medium text-danger-ink">{{ $error }}</p>
    @elseif ($helper)
        <p class="mt-1.5 text-xs text-ink-3">{{ $helper }}</p>
    @endif
</div>
