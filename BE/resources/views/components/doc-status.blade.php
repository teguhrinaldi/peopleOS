@props(['status', 'days' => null])
{{-- Status is never colour alone: every state has its own icon and a text label. --}}
@php
    $value = $status instanceof \BackedEnum ? $status->value : (string) $status;
    [$tone, $icon, $label] = match ($value) {
        'EXPIRING' => ['warning', 'hourglass', 'Expiring soon'],
        'EXPIRED' => ['danger', 'circle-alert', 'Expired'],
        'ARCHIVED' => ['neutral', 'archive', 'Archived'],
        default => ['success', 'circle-check', 'Active'],
    };
@endphp
<span {{ $attributes->class(['badge', "badge-{$tone}"]) }}><x-icon :name="$icon" class="size-3.5" />{{ $label }}@if ($days !== null && $value === 'EXPIRING')<span class="font-normal"> · {{ $days }} d</span>@endif</span>
