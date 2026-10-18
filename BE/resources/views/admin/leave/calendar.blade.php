@extends('layouts.admin')
@section('title', 'Leave calendar')

@section('content')
@php
    use App\Support\LeaveColors;
    $prev = $month->subMonth()->format('Y-m');
    $next = $month->addMonth()->format('Y-m');
    $isTeam = ! auth()->user()->hasAnyPermission('leave.view', 'leave.approve');
    $today = app(\App\Support\CompanyContext::class)->today()->toDateString();
@endphp

<x-page-header title="Leave Requests" :subtitle="($isTeam ? 'Your team' : 'Whole company').' · who is off when'" :breadcrumbs="[['Leave'], ['Leave Requests', route('admin.leave-requests')], ['Calendar']]" />

@include('admin.leave._tabs')

<x-card :padded="false">
    <x-slot:actions>
        <div class="flex items-center gap-1">
            <a href="{{ route('admin.leave-requests', ['tab' => 'calendar', 'month' => $prev]) }}" class="grid size-8 place-items-center rounded-lg text-ink-2 hover:bg-surface-3" aria-label="Previous month"><x-icon name="chevron-left" class="size-4" /></a>
            <span class="min-w-32 text-center text-[13.5px] font-semibold text-ink">{{ $month->format('F Y') }}</span>
            <a href="{{ route('admin.leave-requests', ['tab' => 'calendar', 'month' => $next]) }}" class="grid size-8 place-items-center rounded-lg text-ink-2 hover:bg-surface-3" aria-label="Next month"><x-icon name="chevron-right" class="size-4" /></a>
        </div>
    </x-slot:actions>

    @if ($rows->isEmpty())
        <x-empty-state icon="calendar-days" title="Nobody is off this month" text="Approved and pending leave appears here." class="border-t border-line" />
    @else
        <div class="relative overflow-x-auto border-t border-line">
            <table class="dt dt-sticky text-xs" aria-label="Leave calendar for {{ $month->format('F Y') }}">
                <thead>
                    <tr>
                        <th class="min-w-44">Employee</th>
                        @foreach ($dates as $d)
                            @php $off = $d->isWeekend() || isset($holidays[$d->toDateString()]); @endphp
                            <th class="!px-0 text-center {{ $off ? '!bg-surface-3' : '' }} {{ $d->toDateString() === $today ? '!text-primary-ink' : '' }}" title="{{ $holidays[$d->toDateString()] ?? $d->format('l') }}">
                                <span class="block w-7 text-[10px] font-medium uppercase">{{ substr($d->format('D'), 0, 2) }}</span>
                                <span class="block w-7 text-[12px]">{{ $d->day }}</span>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td class="whitespace-nowrap">
                                <span class="flex items-center gap-2"><x-avatar :name="$row['employee']->full_name" :src="$row['employee']->photo_path" size="xs" />
                                    <span class="font-semibold text-ink">{{ $row['employee']->full_name }}</span></span>
                            </td>
                            @foreach ($dates as $d)
                                @php $cell = $row['cells'][$d->toDateString()] ?? null; $off = $d->isWeekend() || isset($holidays[$d->toDateString()]); @endphp
                                <td class="!px-0.5 !py-1.5 text-center {{ $off ? '!bg-surface-2' : '' }}">
                                    @if ($cell)
                                        <span title="{{ $cell['type'] }}{{ $cell['half'] ? ' ('.$cell['half'].')' : '' }}{{ $cell['pending'] ? ' – pending' : '' }}"
                                            class="mx-auto flex h-6 w-6 items-center justify-center rounded-md text-[10px] font-bold text-white {{ LeaveColors::solid($cell['color']) }} {{ $cell['pending'] ? 'opacity-45 ring-2 ring-inset ring-white/60' : '' }}">
                                            {{ $cell['half'] ? substr($cell['half'], 0, 1) : '' }}<span class="sr-only">{{ $cell['type'] }}{{ $cell['pending'] ? ' pending' : ' approved' }}</span>
                                        </span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    <x-slot:footer>
        <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-ink-2">
            <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded bg-primary"></span>Approved</span>
            <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded bg-primary opacity-45"></span>Pending</span>
            <span class="inline-flex items-center gap-1.5"><span class="grid size-3 place-items-center rounded bg-primary text-[8px] font-bold text-white">A</span>Half day (A = morning, P = afternoon)</span>
            <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded bg-surface-3 ring-1 ring-line"></span>Weekend / holiday</span>
        </div>
    </x-slot:footer>
</x-card>
@endsection
