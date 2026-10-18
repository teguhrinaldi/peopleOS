@props(['items', 'cols' => 1])
{{-- Key/value list. Values may be plain text, a Blade slot string (HtmlString) or null (rendered as an em dash). --}}
<dl {{ $attributes->class(['grid gap-x-8 gap-y-3.5', $cols === 2 ? 'sm:grid-cols-2' : 'grid-cols-1']) }}>
    @foreach ($items as $label => $value)
        <div class="grid grid-cols-1 items-baseline gap-0.5 sm:grid-cols-[minmax(96px,150px)_1fr] sm:gap-4">
            <dt class="text-[12.5px] text-ink-3">{{ $label }}</dt>
            <dd class="min-w-0 break-words text-[13.5px] font-medium text-ink">{{ ($value === null || $value === '') ? '—' : $value }}</dd>
        </div>
    @endforeach
</dl>
