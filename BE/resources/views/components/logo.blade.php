@props(['mark' => false, 'size' => 'md', 'light' => false])
{{-- PeopleOS mark: three people forming a team. Drawn for this project (no third-party asset). --}}
@php $h = ['sm' => 'h-7', 'md' => 'h-9', 'lg' => 'h-12'][$size] ?? 'h-9'; @endphp
<span {{ $attributes->class(['inline-flex items-center gap-2.5']) }}>
    <svg viewBox="0 0 44 40" class="{{ $h }} w-auto" fill="none" aria-hidden="true">
        <circle cx="9" cy="10" r="4.6" fill="#38BDF8"/>
        <path d="M2 28c0-5.2 3.1-8.6 7-8.6s7 3.4 7 8.6v2H2v-2Z" fill="#38BDF8"/>
        <circle cx="33" cy="9" r="4.4" fill="#2563EB"/>
        <path d="M26.5 27c0-5 3-8.2 6.7-8.2 3.7 0 6.8 3.2 6.8 8.2v2h-13.5v-2Z" fill="#2563EB"/>
        <circle cx="21" cy="14" r="5.6" fill="#1D4ED8"/>
        <path d="M11.5 37c0-6.6 4.2-10.6 9.5-10.6s9.5 4 9.5 10.6v1.6h-19V37Z" fill="#1E40AF"/>
    </svg>
    @unless($mark)
        <span class="text-[19px] font-bold tracking-tight {{ $light ? 'text-white' : 'text-ink' }}">PeopleOS</span>
    @endunless
</span>
