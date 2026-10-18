@props(['status' => null, 'tone' => null, 'dot' => true])
@php
    if ($status instanceof \BackedEnum) {
        $status = $status->value;
    }
    $key = strtolower(str_replace([' ', '-'], '_', (string) $status));

    $map = [
        'active' => 'success', 'approved' => 'success', 'on_time' => 'success', 'present' => 'success', 'checked_out' => 'success', 'corrected' => 'success',
        'pending' => 'warning', 'late' => 'warning', 'checked_in' => 'info', 'correction_requested' => 'warning', 'probation' => 'warning',
        'rejected' => 'danger', 'terminated' => 'danger', 'expired' => 'danger', 'absent' => 'danger',
        'processing' => 'info', 'review' => 'warning', 'finalized' => 'success', 'paid' => 'success', 'expiring' => 'warning', 'archived' => 'neutral', 'regular' => 'neutral', 'adjustment' => 'info', 'inactive' => 'neutral', 'cancelled' => 'neutral', 'offline' => 'info', 'draft' => 'neutral',
    ];
    $tone ??= $map[$key] ?? 'neutral';
    $label = $slot->isNotEmpty() ? $slot : \Illuminate\Support\Str::of($key)->replace('_', ' ')->lower()->ucfirst();
@endphp
<span {{ $attributes->class(['badge', "badge-{$tone}", 'badge-dot' => $dot]) }}>{{ $label }}</span>
