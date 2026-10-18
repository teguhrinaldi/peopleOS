<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light">
<head>
    @include('layouts.partials.head')
</head>
@php
    $user = auth()->user();
    $nav = \App\Support\AdminNavigation::forUser($user);
    $activeGroup = collect($nav)->first(fn ($e) => $e['type'] === 'group' && $e['active'])['key'] ?? null;
    $canSearch = $user->hasAnyPermission('employee.view', 'employee.view_team');
    $roleName = $user->roles->first()?->name ?? 'User';
    $bell = app(\App\Services\Admin\NotificationBell::class)->for($user);
@endphp
<body class="min-h-screen antialiased">
<div x-data="sidebar(@js($activeGroup))" class="min-h-screen">
    <a href="#content" class="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-[70] focus:rounded-lg focus:bg-primary focus:px-3 focus:py-2 focus:text-white">Skip to content</a>

    {{-- Desktop sidebar / tablet icon rail --}}
    <aside class="no-print fixed inset-y-0 left-0 z-30 hidden w-[264px] md:block rail-w">
        @include('layouts.partials.sidebar', ['rail' => true])
    </aside>

    {{-- Mobile drawer with the same information architecture --}}
    <x-drawer show="drawer" label="Navigation">
        @include('layouts.partials.sidebar', ['rail' => false])
    </x-drawer>

    <div class="md:pl-[264px] rail-pl">
        <header class="no-print sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-line bg-surface/90 px-4 backdrop-blur sm:px-6 lg:px-8">
            <button type="button" @click="drawer = true" class="grid size-9 place-items-center rounded-lg text-ink-2 hover:bg-surface-3 md:hidden" aria-label="Open menu"><x-icon name="menu" /></button>
            <a href="{{ route('admin.dashboard') }}" class="md:hidden" aria-label="PeopleOS"><x-logo mark size="sm" /></a>

            <form method="GET" action="{{ $canSearch ? route('admin.employees.index') : '#' }}" role="search" class="relative hidden max-w-md flex-1 sm:block">
                <x-icon name="search" class="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-ink-3" />
                <input type="search" name="q" value="" placeholder="Search employees…" class="ctl h-10 rounded-xl pl-10" aria-label="Search employees" @disabled(! $canSearch)>
            </form>

            <div class="ml-auto flex items-center gap-1.5">
                <div x-data="themeToggle">
                    <button type="button" @click="toggle()" class="grid size-9 place-items-center rounded-lg text-ink-2 hover:bg-surface-3" :aria-label="dark ? 'Switch to light theme' : 'Switch to dark theme'">
                        <span x-show="!dark"><x-icon name="moon" /></span><span x-show="dark" x-cloak><x-icon name="sun" /></span>
                    </button>
                </div>

                <x-dropdown width="w-[22rem]">
                    <x-slot:trigger>
                        <button type="button" class="relative grid size-9 place-items-center rounded-lg text-ink-2 hover:bg-surface-3" aria-label="Notifications{{ $bell['unread'] ? ', '.$bell['unread'].' unread' : '' }}">
                            <x-icon name="bell" />
                            @if ($bell['unread'])<span class="absolute -right-0.5 -top-0.5 grid min-w-[18px] place-items-center rounded-full bg-danger px-1 text-[10.5px] font-bold leading-[18px] text-white" aria-hidden="true">{{ $bell['unread'] > 99 ? '99+' : $bell['unread'] }}</span>@endif
                        </button>
                    </x-slot:trigger>
                    <div class="flex items-center justify-between gap-2 px-3 py-2.5">
                        <p class="text-[13px] font-semibold text-ink">Notifications @if ($bell['unread'])<span class="font-normal text-ink-3">({{ $bell['unread'] }} unread)</span>@endif</p>
                        @if ($bell['unread'])
                            <form method="POST" action="{{ route('admin.notifications.read-all') }}">@csrf<button type="submit" class="text-xs font-semibold text-primary-ink hover:underline">Mark all as read</button></form>
                        @endif
                    </div>
                    @forelse ($bell['items'] as $n)
                        <form method="POST" action="{{ route('admin.notifications.read', $n['id']) }}">@csrf
                            <button type="submit" role="menuitem" class="flex w-full items-start gap-3 border-t border-line px-3 py-2.5 text-left hover:bg-surface-2">
                                <span class="mt-0.5 grid size-8 shrink-0 place-items-center rounded-full {{ $n['read'] ? 'bg-surface-3 text-ink-3' : 'bg-primary-soft text-primary-ink' }}"><x-icon :name="$n['icon']" class="size-4" /></span>
                                <span class="min-w-0 flex-1"><span class="block truncate text-[13px] {{ $n['read'] ? 'text-ink-2' : 'font-semibold text-ink' }}">{{ $n['title'] }}@unless ($n['read'])<span class="sr-only"> (unread)</span>@endunless</span>
                                    @if ($n['body'])<span class="block truncate text-xs text-ink-3">{{ $n['body'] }}</span>@endif
                                    <span class="block text-[11px] text-ink-3">{{ $n['at']->diffForHumans() }}</span></span>
                                @unless ($n['read'])<span class="mt-2 size-2 shrink-0 rounded-full bg-primary" aria-hidden="true"></span>@endunless
                            </button>
                        </form>
                    @empty
                        <div class="border-t border-line px-3 pb-5 pt-4 text-center"><x-icon name="bell-off" class="mx-auto size-5 text-ink-3" /><p class="mt-1.5 text-[13px] text-ink-3">You are all caught up.</p></div>
                    @endforelse
                    <a href="{{ route('admin.notifications') }}" role="menuitem" class="block border-t border-line px-3 py-2.5 text-center text-[13px] font-semibold text-primary-ink hover:bg-surface-2">View all notifications</a>
                </x-dropdown>

                <x-dropdown width="w-64">
                    <x-slot:trigger>
                        <button type="button" class="flex items-center gap-2.5 rounded-xl py-1 pl-1 pr-2 hover:bg-surface-3" aria-label="Account menu">
                            <x-avatar :name="$user->name" size="sm" />
                            <span class="hidden text-left leading-tight lg:block">
                                <span class="block text-[13px] font-semibold text-ink">{{ $user->name }}</span>
                                <span class="block text-[11.5px] text-ink-3">{{ $roleName }}</span>
                            </span>
                            <x-icon name="chevron-down" class="hidden size-3.5 text-ink-3 lg:block" />
                        </button>
                    </x-slot:trigger>
                    <div class="border-b border-line px-3 pb-2.5 pt-2">
                        <p class="truncate text-[13px] font-semibold text-ink">{{ $user->name }}</p>
                        <p class="truncate text-xs text-ink-3">{{ $user->email }}</p>
                        <x-badge tone="info" :dot="false" class="mt-1.5">{{ $roleName }}</x-badge>
                    </div>
                    @if ($user->employee)
                        <x-dropdown-item :href="route('admin.employees.show', $user->employee)" icon="user">My employee profile</x-dropdown-item>
                    @endif
                    <form method="POST" action="{{ route('logout') }}">@csrf
                        <x-dropdown-item type="submit" icon="log-out" danger>Sign out</x-dropdown-item>
                    </form>
                </x-dropdown>
            </div>
        </header>

        <main id="content" class="mx-auto w-full max-w-[1480px] px-4 py-6 sm:px-6 lg:px-8">
            @yield('content')
        </main>
    </div>
</div>

<x-confirm />
<x-flash />
@stack('modals')
</body>
</html>
