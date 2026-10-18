@extends('layouts.admin')
@section('title', 'Dashboard')

@section('content')
@php
    $k = $d['kpis'];
    $user = auth()->user();
    $team = $d['scope'] === 'team';
@endphp

<div class="mb-6 flex flex-wrap items-center justify-between gap-4">
    <div class="flex items-center gap-4">
        <x-avatar :name="$user->name" size="lg" />
        <div>
            <h1 class="text-xl font-bold tracking-tight text-ink sm:text-2xl">{{ $d['greeting'] }}</h1>
            <p class="text-[13px] text-ink-3">{{ $user->roles->first()?->name }} &middot; {{ $team ? 'Showing your team' : 'Showing the whole company' }}</p>
        </div>
    </div>
    <p class="text-[13px] font-medium text-ink-2">{{ $d['date']->format('D, d M Y') }}</p>
</div>

<section aria-label="Key figures" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-5">
    <x-stat-card :label="$team ? 'Team members' : 'Employees'" :value="$k['total']" icon="users" tone="primary"
        :hint="$k['active'].' active'.($k['pending'] ? ' · '.$k['pending'].' pending' : '')" :href="$user->hasAnyPermission('employee.view', 'employee.view_team') ? route('admin.employees.index') : null" />
    <x-stat-card label="Present today" :value="$k['present']" icon="user-check" tone="success"
        :hint="$k['presentRate'] !== null ? $k['presentRate'].' %' : ($d['workingDay'] ? 'No expected staff' : 'Not a working day')" hintTone="success" :progress="$k['presentRate']" />
    <x-stat-card label="Late today" :value="$k['late']" icon="clock" tone="warning"
        :hint="$k['lateRate'] !== null ? $k['lateRate'].' % of present' : '—'" hintTone="danger" />
    <x-stat-card label="Not checked in yet" :value="$k['notYet']" icon="user-minus" tone="danger"
        :hint="$d['workingDay'] ? 'of '.$k['expected'].' expected' : 'Rest day or holiday'" />
    <x-stat-card label="On leave today" :value="$k['onLeave']" icon="calendar-days" tone="primary"
        :hint="$k['onLeaveHalf'] ? $k['onLeaveHalf'].' half day' : 'Approved leave'" :href="$d['upcomingLeave'] !== null ? route('admin.leave-requests', ['tab' => 'calendar']) : null" />
</section>

@if ($d['approvals'])
    <section class="mt-8" aria-labelledby="approvals-h">
        <h2 id="approvals-h" class="mb-3 text-[15px] font-semibold text-ink">Pending approvals</h2>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($d['approvals'] as $card)
                <x-stat-card :label="$card['label']" :value="$card['count']" :icon="$card['icon']" :tone="$card['tone']" :href="$card['url']" />
            @endforeach
        </div>
    </section>
@endif

<div class="mt-8 grid grid-cols-1 gap-6 lg:grid-cols-5">
    <x-card title="Attendance overview" subtitle="Today, company time" class="lg:col-span-2">
        <x-chart.donut :segments="$d['donut']" :total="array_sum(array_column($d['donut'], 'value'))" label="Today" />
        @unless ($d['workingDay'])
            <p class="mt-4 rounded-lg bg-neutral-soft px-3 py-2 text-xs text-neutral-ink">Today is a rest day or holiday in the default calendar, so nobody is expected.</p>
        @endunless
    </x-card>

    <section class="card lg:col-span-3" x-data="trendCard(@js(route('admin.dashboard.trend')), {{ $d['trendMeta']['range'] }})" @trend-retry="loadRange()" aria-labelledby="trend-h">
        <header class="flex flex-wrap items-start justify-between gap-3 px-5 pb-3 pt-4">
            <h2 id="trend-h" class="text-[15px] font-semibold text-ink">Attendance trend</h2>
            <x-select name="trend_days" aria-label="Reporting period" :options="[7 => 'Last 7 days', 14 => 'Last 14 days', 30 => 'Last 30 days']" :value="$d['trendMeta']['range']" class="w-40" @change="loadRange($event.target.value)" />
        </header>
        <div class="relative px-5 pb-5" :aria-busy="loading ? 'true' : 'false'">
            <div x-ref="body"><x-chart.attendance-trend :days="$d['trend']" :meta="$d['trendMeta']" /></div>
            <div x-show="loading" x-cloak class="absolute inset-0 grid place-items-center rounded-b-xl bg-surface/70" role="status"><span class="text-[13px] font-medium text-ink-2">Loading…</span></div>
        </div>
    </section>
</div>

<div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-5">
    @if ($d['upcomingLeave'] !== null)
        <x-card :title="$team ? 'Team leave' : 'Who is off'" subtitle="Now and the next 14 days" class="lg:col-span-3">
            <x-slot:actions><x-button variant="text" size="sm" :href="route('admin.leave-requests', ['tab' => 'calendar'])" iconRight="chevron-right">Calendar</x-button></x-slot:actions>
            @if ($d['upcomingLeave']->isEmpty())
                <p class="py-6 text-center text-[13px] text-ink-3">Nobody is off in the next two weeks.</p>
            @else
                <ul class="divide-y divide-line">
                    @foreach ($d['upcomingLeave'] as $l)
                        <li class="flex items-center gap-3 py-2.5 first:pt-0 last:pb-0">
                            <x-avatar :name="$l->employee->full_name" :src="$l->employee->photo_path" size="sm" />
                            <span class="min-w-0 flex-1"><span class="block truncate text-[13.5px] font-semibold text-ink">{{ $l->employee->full_name }}</span>
                                <span class="text-xs text-ink-3">{{ \App\Support\DateDisplay::range($l->start_date, $l->end_date) }}</span></span>
                            <x-leave-type :type="$l->leaveType" />
                            @if ($l->status->value === 'PENDING')<x-badge status="pending" />@endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>
    @endif
    @if ($d['payroll'] || $d['documents'])
        <section aria-label="Payroll and documents" class="grid grid-cols-1 gap-4 {{ $d['upcomingLeave'] !== null ? 'lg:col-span-2' : 'lg:col-span-5 sm:grid-cols-2' }}">
            @if ($d['payroll'])
                @if ($d['payroll']['none'])
                    <x-stat-card label="Payroll status" value="None yet" icon="wallet" tone="muted" hint="No payroll period created" :href="route('admin.payroll')" />
                @else
                    @php $ps = $d['payroll']['status']; $psTone = ['PAID' => 'success', 'FINALIZED' => 'success', 'APPROVED' => 'primary', 'REVIEW' => 'warning', 'PROCESSING' => 'primary', 'DRAFT' => 'muted'][$ps->value] ?? 'muted'; @endphp
                    <x-stat-card label="Payroll status" :value="ucfirst(strtolower($ps->value))" icon="wallet" :tone="$psTone"
                        :hint="$d['payroll']['name'].($d['payroll']['net'] ? ' · net '.$d['payroll']['net'] : ' · not generated')" :href="$d['payroll']['url']" />
                @endif
            @endif
            @if ($d['documents'])
                <x-stat-card label="Expiring documents" :value="$d['documents']['expiring']" icon="folder" :tone="$d['documents']['expired'] ? 'danger' : ($d['documents']['expiring'] ? 'warning' : 'success')"
                    :hint="($d['documents']['expired'] ? $d['documents']['expired'].' expired · ' : '').'within '.$d['documents']['days'].' days'"
                    :href="route('admin.documents', ['status' => $d['documents']['expiring'] || ! $d['documents']['expired'] ? 'EXPIRING' : 'EXPIRED'])" />
            @endif
        </section>
    @endif
</div>

@if ($d['activity'] !== null)
    <x-card title="Recent activity" class="mt-6">
        <x-slot:actions>
            <x-button variant="text" size="sm" :href="route('admin.activity')" iconRight="chevron-right">View all</x-button>
        </x-slot:actions>
        @if ($d['activity']->isEmpty())
            <p class="py-6 text-center text-[13px] text-ink-3">Nothing has happened yet.</p>
        @else
            <ul class="divide-y divide-line">
                @foreach ($d['activity'] as $a)
                    <li class="flex items-start gap-3 py-3 first:pt-0 last:pb-0">
                        @php $tones = ['primary' => 'bg-primary-soft text-primary-ink', 'success' => 'bg-success-soft text-success-ink', 'warning' => 'bg-warning-soft text-warning-ink', 'danger' => 'bg-danger-soft text-danger-ink', 'muted' => 'bg-neutral-soft text-neutral-ink']; @endphp
                        <span class="grid size-8 shrink-0 place-items-center rounded-full {{ $tones[$a['tone']] }}"><x-icon :name="$a['icon']" class="size-4" /></span>
                        <p class="min-w-0 flex-1 text-[13.5px] text-ink-2">
                            <span class="font-semibold text-ink">{{ $a['actor'] }}</span> {{ $a['verb'] }}
                            @if ($a['subject'])
                                @if ($a['subject_url'])<a href="{{ $a['subject_url'] }}" class="font-semibold text-primary-ink hover:underline">{{ $a['subject'] }}</a>@else<span class="font-semibold text-ink">{{ $a['subject'] }}</span>@endif
                            @endif
                        </p>
                        <time class="shrink-0 text-xs text-ink-3" datetime="{{ $a['at']->toIso8601String() }}">{{ $a['at']->format('d M, H:i') }}</time>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-card>
@endif
@endsection
