@extends('layouts.admin')
@section('title', 'Attendance record')

@section('content')
@php
    use App\Support\Fmt;
    $employee = $attendance->employee;
    $s = $schedule;
    $status = $attendance->needs_review ? 'pending' : strtolower($attendance->status->value);
    $reopen = old('_form') === 'review';
    $ctx = app(\App\Support\CompanyContext::class);
@endphp

<div class="mb-4"><a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('admin.attendance') }}" class="no-print inline-flex items-center gap-1.5 text-[13px] font-medium text-ink-3 hover:text-primary-ink"><x-icon name="arrow-left" class="size-4" />Back</a></div>

<x-card class="mb-6">
    <div class="flex flex-wrap items-start justify-between gap-5">
        <div class="flex min-w-0 items-center gap-4">
            <x-avatar :name="$employee->full_name" :src="$employee->photo_path" size="lg" />
            <div class="min-w-0">
                <h1 class="truncate text-xl font-bold tracking-tight text-ink">{{ $employee->full_name }}</h1>
                <p class="text-[13px] text-ink-3">{{ $employee->employee_number }} · {{ $attendance->work_date->format('l, d M Y') }}</p>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <x-badge :status="$status">{{ $attendance->needs_review ? 'Needs review' : '' }}</x-badge>
                    @if ($attendance->is_late)<x-badge status="late">Late {{ $attendance->late_minutes }}m</x-badge>@endif
                    @if ($attendance->is_early_leave)<x-badge tone="warning">Left {{ $attendance->early_leave_minutes }}m early</x-badge>@endif
                    @if ($events->contains('is_offline_sync', true))<x-badge tone="info">Synced from offline</x-badge>@endif
                </div>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2 no-print">
            @can('view', $employee)
                <x-button variant="outline" icon="user" :href="route('admin.employees.show', $employee)">Profile</x-button>
            @endcan
            @if ($canReview)
                <x-button variant="danger-soft" icon="circle-alert" :dispatch="['name' => 'review-form', 'data' => ['decision' => 'reject', 'title' => 'Reject attendance', 'note' => '']]">Reject</x-button>
                <x-button icon="circle-check" :dispatch="['name' => 'review-form', 'data' => ['decision' => 'approve', 'title' => 'Approve attendance', 'note' => '']]">Approve</x-button>
            @endif
        </div>
    </div>
    <dl class="mt-6 grid grid-cols-2 gap-4 border-t border-line pt-5 sm:grid-cols-4">
        <div><dt class="text-xs text-ink-3">Check in</dt><dd class="mt-1 text-lg font-bold tabular-nums text-ink">{{ Fmt::time($attendance->check_in_at) ?? '—' }}</dd></div>
        <div><dt class="text-xs text-ink-3">Check out</dt><dd class="mt-1 text-lg font-bold tabular-nums text-ink">{{ Fmt::time($attendance->check_out_at) ?? '—' }}</dd></div>
        <div><dt class="text-xs text-ink-3">Worked</dt><dd class="mt-1 text-lg font-bold text-ink"><x-duration :minutes="$attendance->worked_minutes" /></dd></div>
        <div><dt class="text-xs text-ink-3">Reviewed</dt><dd class="mt-1 text-[13px] font-medium text-ink">
            @if ($attendance->reviewed_at){{ $reviewerName ?? 'Someone' }} · {{ $ctx->toLocal($attendance->reviewed_at)->format('d M, H:i') }}@if ($attendance->review_note)<span class="block text-xs font-normal text-ink-2">“{{ $attendance->review_note }}”</span>@endif
            @else — @endif
        </dd></div>
    </dl>
</x-card>

<div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
    <x-card title="Schedule snapshot" subtitle="Frozen when the day was first scheduled. Later edits to shifts or locations never change it." class="xl:col-span-2">
        @if ($s)
            <x-kv cols="2" :items="[
                'Day type' => ucfirst(str_replace('_', ' ', $s->day_type->value)),
                'Shift' => $s->shift_name,
                'Expected' => $s->expected_start_at ? Fmt::time($s->expected_start_at).' → '.Fmt::time($s->expected_end_at).($s->expected_start_at->copy()->setTimezone($s->timezone)->toDateString() !== $s->expected_end_at->copy()->setTimezone($s->timezone)->toDateString() ? ' (+1)' : '') : null,
                'Working time' => $s->expected_working_minutes ? intdiv($s->expected_working_minutes, 60).'h '.str_pad((string) ($s->expected_working_minutes % 60), 2, '0', STR_PAD_LEFT).'m' : null,
                'Grace' => $s->grace_late_minutes.'m late · '.$s->grace_early_leave_minutes.'m early',
                'Unpaid breaks' => $s->break_minutes.'m',
                'Location' => $s->location_name,
                'Radius' => $s->location_radius_meters ? number_format($s->location_radius_meters).' m' : null,
                'Geofence' => ucfirst($s->geofence_mode->value).($s->require_gps ? ' · GPS required' : ''),
                'Selfie' => $s->require_selfie ? 'Required' : 'Not required',
                'GPS accuracy' => '≤ '.$s->max_gps_accuracy_meters.' m',
                'Time zone' => $s->timezone,
            ]" />
        @else
            <p class="text-[13px] text-ink-3">No schedule snapshot.</p>
        @endif
    </x-card>

    <x-card title="Positions">
        @if ($s?->location_radius_meters)
            <x-geo.radius :radius="$s->location_radius_meters" :points="$points" />
        @else
            <p class="py-6 text-center text-[13px] text-ink-3">No geofence for this day.</p>
        @endif
    </x-card>
</div>

<x-card title="Events" subtitle="Server time is authoritative; device time is kept for diagnostics." class="mt-6" :padded="false">
    @if ($events->isEmpty())
        <p class="border-t border-line px-5 py-6 text-center text-[13px] text-ink-3">No check-in or check-out events.</p>
    @else
        <div class="relative overflow-x-auto border-t border-line">
            <table class="dt">
                <thead><tr><th>Event</th><th>Time</th><th>Received</th><th>Coordinates</th><th>Accuracy</th><th>Distance</th><th>Flags</th><th>Selfie</th></tr></thead>
                <tbody>
                    @foreach ($events as $e)
                        <tr>
                            <td class="font-semibold text-ink">{{ $e->type->value === 'CHECK_IN' ? 'Check in' : 'Check out' }}</td>
                            <td class="whitespace-nowrap tabular-nums">{{ $ctx->toLocal($e->occurred_at)->format('d M, H:i:s') }}</td>
                            <td class="whitespace-nowrap tabular-nums text-xs">{{ $ctx->toLocal($e->received_at)->format('d M, H:i:s') }}<span class="block text-ink-3">{{ $e->time_source === 'device_offline' ? 'device clock (offline)' : 'server clock' }}</span></td>
                            <td class="whitespace-nowrap font-mono text-xs">{{ $e->latitude !== null ? number_format($e->latitude, 6).', '.number_format($e->longitude, 6) : '—' }}</td>
                            <td class="tabular-nums">{{ $e->accuracy_meters !== null ? round($e->accuracy_meters).' m' : '—' }}</td>
                            <td class="whitespace-nowrap">
                                @if ($e->distance_meters !== null)
                                    <span @class(['font-semibold', 'text-success-ink' => $e->inside_geofence !== false, 'text-danger-ink' => $e->inside_geofence === false])>{{ number_format($e->distance_meters) }} m</span>
                                    <span class="text-xs text-ink-3">{{ $e->inside_geofence === false ? 'outside' : ($e->inside_geofence ? 'inside' : '') }}</span>
                                @else — @endif
                            </td>
                            <td class="space-x-1 whitespace-nowrap">
                                @if ($e->is_offline_sync)<x-badge tone="info" :dot="false">Offline</x-badge>@endif
                                @if ($e->is_mock_location)<x-badge tone="danger" :dot="false">Mock GPS</x-badge>@endif
                                @if (! $e->is_offline_sync && ! $e->is_mock_location)<span class="text-ink-3">—</span>@endif
                            </td>
                            <td>
                                @if ($e->selfie_path && $canSeeSelfie)
                                    <a href="{{ route('admin.attendance.selfie', $e) }}" target="_blank" rel="noopener" class="block size-12 overflow-hidden rounded-lg ring-1 ring-line hover:ring-primary">
                                        <img src="{{ route('admin.attendance.selfie', $e) }}" alt="Selfie at {{ $e->type->value === 'CHECK_IN' ? 'check-in' : 'check-out' }}" class="size-full object-cover" loading="lazy">
                                    </a>
                                @else <span class="text-ink-3">—</span> @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-card>

@if ($corrections->isNotEmpty())
    <x-card title="Correction requests" class="mt-6">
        <x-timeline :items="$corrections->map(fn ($c) => [
            'title' => ucfirst(strtolower($c->status->value)).' correction: '.collect([$c->requested_check_in_at ? 'in '.Fmt::time($c->requested_check_in_at) : null, $c->requested_check_out_at ? 'out '.Fmt::time($c->requested_check_out_at) : null])->filter()->join(', '),
            'meta' => 'Requested '.$ctx->toLocal($c->created_at)->format('d M, H:i').($c->reviewed_at ? ' · decided by '.($c->reviewer?->name ?? 'someone').' '.$ctx->toLocal($c->reviewed_at)->format('d M, H:i') : ''),
            'body' => $c->reason.($c->review_note ? ' — Reviewer: '.$c->review_note : ''),
            'tone' => ['PENDING' => 'warning', 'APPROVED' => 'success', 'REJECTED' => 'danger'][$c->status->value] ?? 'muted',
            'icon' => 'clipboard-check',
        ])->all()" />
    </x-card>
@endif

@if ($canReview)
    <x-modal name="review-form" title="Review attendance" :open="$reopen" :initial="['decision' => old('decision', 'approve'), 'note' => old('note', ''), 'title' => old('decision') === 'reject' ? 'Reject attendance' : 'Approve attendance']">
        <form id="review-form" method="POST" action="{{ route('admin.attendance.review', $attendance) }}" class="space-y-4">
            @csrf
            <input type="hidden" name="_form" value="review">
            <input type="hidden" name="decision" :value="data.decision">
            <p class="text-[13.5px] text-ink-2" x-show="data.decision === 'approve'">The record is accepted as captured and leaves the review queue.</p>
            <p class="text-[13.5px] text-ink-2" x-show="data.decision === 'reject'">The record is marked rejected and does not count as attendance. The employee sees your reason.</p>
            <x-mfield name="note" as="textarea" label="Note" :rows="3" helper="Required when rejecting." />
        </form>
        <x-slot:footer>
            <x-button variant="outline" @click="hide()">Cancel</x-button>
            <button type="submit" form="review-form" class="btn btn-md" :class="data.decision === 'reject' ? 'btn-danger' : 'btn-primary'" x-text="data.decision === 'reject' ? 'Reject' : 'Approve'"></button>
        </x-slot:footer>
    </x-modal>
@endif
@endsection
