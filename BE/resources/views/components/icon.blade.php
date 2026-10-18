@props(['name', 'stroke' => 2])
@php $sized = str_contains((string) $attributes->get('class'), 'size-'); @endphp
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="{{ $stroke }}" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" {{ $attributes->class(['size-[18px]' => ! $sized, 'shrink-0']) }}>{!! \App\Support\Icons::inner($name) !!}</svg>
