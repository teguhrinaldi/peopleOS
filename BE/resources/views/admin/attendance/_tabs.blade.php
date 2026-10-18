@php
    $tabs = [['key' => 'board', 'label' => 'Daily board', 'icon' => 'clock', 'href' => route('admin.attendance')]];
    if (auth()->user()->hasAnyPermission('attendance.correct', 'attendance.approve_team')) {
        $tabs[] = ['key' => 'corrections', 'label' => 'Corrections', 'icon' => 'clipboard-check', 'href' => route('admin.attendance.corrections')];
    }
    if (auth()->user()->hasPermission('attendance.review')) {
        $tabs[] = ['key' => 'review', 'label' => 'Needs review', 'icon' => 'flag', 'href' => route('admin.attendance', ['review' => 1])];
    }
@endphp
<x-tabs class="mb-6" :active="$active" :tabs="$tabs" />
