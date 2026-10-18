@props(['name', 'label' => null, 'value' => null, 'helper' => null, 'required' => false, 'rows' => 3])
@php
    $id = $attributes->get('id', 'f-'.trim(preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
    $error = $errors->first($name);
@endphp
<div {{ $attributes->only('class') }}>
    @if ($label)
        <label for="{{ $id }}" class="mb-1.5 block text-[13px] font-medium text-ink">{{ $label }}@if ($required)<span class="text-danger"> *</span>@endif</label>
    @endif
    <textarea id="{{ $id }}" name="{{ $name }}" rows="{{ $rows }}" {{ $attributes->except(['class', 'id'])->merge(['class' => 'ctl']) }} @required($required) @if ($error) aria-invalid="true" @endif>{{ old($name, $value) }}</textarea>
    @if ($error)
        <p class="mt-1.5 text-xs font-medium text-danger-ink">{{ $error }}</p>
    @elseif ($helper)
        <p class="mt-1.5 text-xs text-ink-3">{{ $helper }}</p>
    @endif
</div>
