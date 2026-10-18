@props(['items'])
{{-- $items: list of ['title', 'meta'?, 'body'?, 'tone'? (primary|success|warning|danger|muted), 'icon'?] --}}
@php $tones = ['primary' => 'bg-primary-soft text-primary-ink', 'success' => 'bg-success-soft text-success-ink', 'warning' => 'bg-warning-soft text-warning-ink', 'danger' => 'bg-danger-soft text-danger-ink', 'muted' => 'bg-neutral-soft text-neutral-ink']; @endphp
<ol {{ $attributes->class(['relative space-y-5']) }}>
    @foreach ($items as $item)
        <li class="relative flex gap-3.5">
            @unless ($loop->last)<span class="absolute left-[15px] top-8 -bottom-5 w-px bg-line" aria-hidden="true"></span>@endunless
            <span class="z-[1] grid size-8 shrink-0 place-items-center rounded-full {{ $tones[$item['tone'] ?? 'primary'] }}"><x-icon :name="$item['icon'] ?? 'circle-check'" class="size-4" /></span>
            <div class="min-w-0 flex-1 pt-0.5">
                <p class="text-[13.5px] font-semibold text-ink">{{ $item['title'] }}</p>
                @if (! empty($item['meta']))<p class="mt-0.5 text-xs text-ink-3">{{ $item['meta'] }}</p>@endif
                @if (! empty($item['body']))<p class="mt-1.5 text-[13px] leading-relaxed text-ink-2">{{ $item['body'] }}</p>@endif
            </div>
        </li>
    @endforeach
</ol>
