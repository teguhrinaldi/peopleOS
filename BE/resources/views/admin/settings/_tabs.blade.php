{{-- Settings area tabs. Each tab appears only for its permission: HR (activity.view without settings.manage) sees just the audit log. --}}
@php
    $user = auth()->user();
    $tabs = [];
    if ($user->hasPermission('settings.manage')) {
        $tabs[] = ['key' => 'general', 'label' => 'General', 'icon' => 'building', 'href' => route('admin.settings')];
        $tabs[] = ['key' => 'roles', 'label' => 'Roles & permissions', 'icon' => 'key-round', 'href' => route('admin.settings.roles')];
    }
    if ($user->hasPermission('activity.view')) {
        $tabs[] = ['key' => 'audit', 'label' => 'Audit log', 'icon' => 'history', 'href' => route('admin.activity')];
    }
@endphp
<x-tabs class="mb-6" :active="$active" :tabs="$tabs" />
