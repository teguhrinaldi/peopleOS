@props(['name', 'label', 'as' => 'input', 'type' => 'text', 'options' => [], 'placeholder' => null, 'helper' => null, 'required' => false, 'rows' => 3])
{{-- Field bound to the surrounding modal's `data` object (Alpine x-model). Server errors from a failed submit show under it. --}}
@php
    $id = 'm-'.$attributes->get('id', $name).'-'.\Illuminate\Support\Str::random(4);
    $error = $errors->first($name);
@endphp
<div {{ $attributes->only('class') }}>
    <label for="{{ $id }}" class="mb-1.5 block text-[13px] font-medium text-ink">{{ $label }}@if ($required)<span class="text-danger"> *</span>@endif</label>
    @if ($as === 'select')
        <x-listbox><select id="{{ $id }}" name="{{ $name }}" x-model="data.{{ $name }}" class="ctl" @if ($error) aria-invalid="true" @endif>
            @if ($placeholder !== null)<option value="">{{ $placeholder }}</option>@endif
            @foreach ($options as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach
        </select></x-listbox>
    @elseif ($as === 'textarea')
        <textarea id="{{ $id }}" name="{{ $name }}" rows="{{ $rows }}" x-model="data.{{ $name }}" class="ctl" @if ($error) aria-invalid="true" @endif></textarea>
    @elseif ($as === 'toggle')
        <label class="flex cursor-pointer items-center gap-3">
            <input type="hidden" name="{{ $name }}" value="0">
            <input id="{{ $id }}" type="checkbox" name="{{ $name }}" value="1" x-model="data.{{ $name }}" class="size-4 rounded accent-[var(--primary)]">
            <span class="text-[13px] text-ink-2">{{ $helper }}</span>
        </label>
    @else
        <input id="{{ $id }}" type="{{ $type }}" name="{{ $name }}" x-model="data.{{ $name }}" placeholder="{{ $placeholder }}" class="ctl" @required($required) @if ($error) aria-invalid="true" @endif {{ $attributes->except(['class', 'id']) }}>
    @endif
    @if ($error)
        <p class="mt-1.5 text-xs font-medium text-danger-ink">{{ $error }}</p>
    @elseif ($helper && $as !== 'toggle')
        <p class="mt-1.5 text-xs text-ink-3">{{ $helper }}</p>
    @endif
</div>
