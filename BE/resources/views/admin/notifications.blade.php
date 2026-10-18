@extends('layouts.admin')
@section('title', 'Notifications')

@section('content')
<x-page-header title="Notifications" subtitle="Updates addressed to you, newest first." :breadcrumbs="[['Notifications']]">
    <form method="POST" action="{{ route('admin.notifications.read-all') }}">@csrf<x-button type="submit" variant="outline" icon="check-check">Mark all as read</x-button></form>
</x-page-header>

<x-card :padded="false">
    @if ($notifications->isEmpty())
        <x-empty-state icon="bell" title="You are all caught up" text="New approvals, decisions and reminders will show up here." />
    @else
        <ul class="divide-y divide-line">
            @foreach ($notifications as $n)
                <li>
                    <form method="POST" action="{{ route('admin.notifications.read', $n['id']) }}">@csrf
                        <button type="submit" class="flex w-full items-start gap-3 px-5 py-3.5 text-left hover:bg-surface-2">
                            <span class="mt-0.5 grid size-9 shrink-0 place-items-center rounded-full {{ $n['read'] ? 'bg-surface-3 text-ink-3' : 'bg-primary-soft text-primary-ink' }}"><x-icon :name="$n['icon']" class="size-4" /></span>
                            <span class="min-w-0 flex-1"><span class="block text-[13.5px] {{ $n['read'] ? 'text-ink-2' : 'font-semibold text-ink' }}">{{ $n['title'] }}@unless ($n['read'])<span class="sr-only"> (unread)</span>@endunless</span>
                                @if ($n['body'])<span class="block truncate text-[13px] text-ink-3">{{ $n['body'] }}</span>@endif</span>
                            <time class="shrink-0 text-xs text-ink-3" datetime="{{ $n['at']->toIso8601String() }}">{{ \App\Support\DateDisplay::moment($n['at']) }}</time>
                        </button>
                    </form>
                </li>
            @endforeach
        </ul>
        <div class="flex items-center justify-between gap-3 border-t border-line px-4 py-3"><x-pagination :paginator="$notifications" /></div>
    @endif
</x-card>
@endsection
