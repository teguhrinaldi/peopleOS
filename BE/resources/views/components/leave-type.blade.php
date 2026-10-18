@props(['type'])
{{-- Leave type chip: colour token dot + name (status is never colour-only). --}}
<span {{ $attributes->class(['inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-semibold', \App\Support\LeaveColors::soft($type?->color)]) }}>
    <span class="size-1.5 rounded-full {{ \App\Support\LeaveColors::solid($type?->color) }}" aria-hidden="true"></span>{{ $type?->name ?? 'Leave' }}
</span>
