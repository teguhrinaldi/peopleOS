@php
    $tabs = [];
    if ($canReview) {
        $tabs[] = ['key' => 'pending', 'label' => 'To review', 'icon' => 'clipboard-check', 'href' => route('admin.leave-requests')];
    }
    $tabs[] = ['key' => 'history', 'label' => 'All requests', 'icon' => 'history', 'href' => route('admin.leave-requests', ['tab' => 'history'])];
    $tabs[] = ['key' => 'calendar', 'label' => 'Calendar', 'icon' => 'calendar-days', 'href' => route('admin.leave-requests', ['tab' => 'calendar'])];
@endphp
<x-tabs class="mb-6" :active="$tab" :tabs="$tabs" />
