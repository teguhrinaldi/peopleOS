@extends('layouts.admin')
@section('title', 'Attendance policies')

@section('content')
@php
    $modes = ['enforce' => 'Enforce: refuse check-ins outside the radius', 'review' => 'Review: accept, but flag for HR', 'off' => 'Off: no geofence check'];
    $blank = ['id' => null, 'action' => route('admin.work-locations.policies.store'), 'title' => 'New attendance policy', 'name' => '', 'is_default' => false,
        'require_gps' => true, 'geofence_mode' => 'enforce', 'require_selfie' => false, 'max_gps_accuracy_meters' => 100, 'reject_mock_location' => true, 'offline_max_age_minutes' => 720];
    $reopen = old('_form') === 'policy';
    $initial = $reopen ? array_merge($blank, [
        'id' => old('_id'), 'action' => old('_action'), 'title' => old('_id') ? 'Edit attendance policy' : 'New attendance policy', 'name' => old('name'),
        'is_default' => (bool) old('is_default'), 'require_gps' => (bool) old('require_gps'), 'geofence_mode' => old('geofence_mode'), 'require_selfie' => (bool) old('require_selfie'),
        'max_gps_accuracy_meters' => old('max_gps_accuracy_meters'), 'reject_mock_location' => (bool) old('reject_mock_location'), 'offline_max_age_minutes' => old('offline_max_age_minutes'),
    ]) : $blank;
@endphp

<x-page-header title="Work Locations" subtitle="Policies decide how strictly check-ins are verified at the locations that use them."
    :breadcrumbs="[['Attendance'], ['Work Locations', route('admin.work-locations')], ['Attendance policies']]">
    <x-button icon="plus" :dispatch="['name' => 'policy-form', 'data' => $blank]">Add policy</x-button>
</x-page-header>

@include('admin.locations._tabs', ['active' => 'policies'])

<div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
    @forelse ($policies as $p)
        @php
            $count = (int) ($locationCounts[$p->id] ?? 0);
            $edit = ['id' => $p->id, 'action' => route('admin.work-locations.policies.update', $p), 'title' => 'Edit attendance policy', 'name' => $p->name, 'is_default' => $p->is_default,
                'require_gps' => $p->require_gps, 'geofence_mode' => $p->geofence_mode->value, 'require_selfie' => $p->require_selfie, 'max_gps_accuracy_meters' => $p->max_gps_accuracy_meters,
                'reject_mock_location' => $p->reject_mock_location, 'offline_max_age_minutes' => $p->offline_max_age_minutes];
            $del = ['title' => 'Delete '.$p->name.'?', 'text' => 'The policy will be removed. Attendance already recorded keeps the rules it was captured with.', 'action' => route('admin.work-locations.policies.destroy', $p), 'method' => 'DELETE', 'label' => 'Delete policy'];
            $modeTone = ['enforce' => 'success', 'review' => 'warning', 'off' => 'neutral'][$p->geofence_mode->value];
        @endphp
        <x-card>
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="flex flex-wrap items-center gap-2 text-[15px] font-semibold text-ink">{{ $p->name }} @if ($p->is_default)<x-badge tone="info" :dot="false">Default</x-badge>@endif</h2>
                    <p class="mt-0.5 text-[12.5px] text-ink-3">Used by {{ $count }} {{ \Illuminate\Support\Str::plural('location', $count) }}{{ $p->is_default ? ' and every location without its own policy' : '' }}</p>
                </div>
                <div class="flex shrink-0 gap-1">
                    <x-button variant="text" size="sm" icon="pencil" :dispatch="['name' => 'policy-form', 'data' => $edit]" :aria-label="'Edit '.$p->name" />
                    @unless ($p->is_default)
                        <x-button variant="text" size="sm" icon="trash-2" class="text-danger-ink" :dispatch="['name' => 'confirm', 'data' => $del]" :aria-label="'Delete '.$p->name" />
                    @endunless
                </div>
            </div>
            <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-3 text-[13px]">
                <div><dt class="text-xs text-ink-3">Geofence</dt><dd class="mt-0.5"><x-badge :tone="$modeTone" :dot="false">{{ ucfirst($p->geofence_mode->value) }}</x-badge></dd></div>
                <div><dt class="text-xs text-ink-3">GPS</dt><dd class="mt-0.5 font-medium text-ink">{{ $p->require_gps ? 'Required' : 'Optional' }} · ≤ {{ $p->max_gps_accuracy_meters }} m accuracy</dd></div>
                <div><dt class="text-xs text-ink-3">Selfie</dt><dd class="mt-0.5 font-medium text-ink">{{ $p->require_selfie ? 'Required' : 'Not required' }}</dd></div>
                <div><dt class="text-xs text-ink-3">Mock locations</dt><dd class="mt-0.5 font-medium text-ink">{{ $p->reject_mock_location ? 'Rejected' : 'Allowed' }}</dd></div>
                <div class="col-span-2"><dt class="text-xs text-ink-3">Offline check-ins</dt><dd class="mt-0.5 font-medium text-ink">{{ $p->offline_max_age_minutes ? 'Accepted up to '.intdiv($p->offline_max_age_minutes, 60).'h '.($p->offline_max_age_minutes % 60).'m old' : 'Not accepted' }}</dd></div>
            </dl>
        </x-card>
    @empty
        <x-card class="lg:col-span-2" :padded="false"><x-empty-state icon="shield-check" title="No attendance policies" text="Without a policy, check-ins require GPS inside the radius and no selfie." /></x-card>
    @endforelse
</div>

<x-modal name="policy-form" :open="$reopen" :initial="$initial" title="Attendance policy" size="lg">
    <form id="policy-form" method="POST" :action="data.action" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        @csrf
        <input type="hidden" name="_form" value="policy">
        <input type="hidden" name="_id" :value="data.id">
        <input type="hidden" name="_action" :value="data.action">
        <template x-if="data.id"><input type="hidden" name="_method" value="PUT"></template>
        <x-mfield name="name" label="Name" required placeholder="Standard (GPS)" class="sm:col-span-2" />
        <x-mfield name="geofence_mode" as="select" label="Geofence" :options="$modes" class="sm:col-span-2" />
        <x-mfield name="max_gps_accuracy_meters" type="number" min="5" max="1000" label="Max GPS accuracy (m)" helper="Readings less precise than this are refused." />
        <x-mfield name="offline_max_age_minutes" type="number" min="0" max="10080" label="Offline window (minutes)" helper="How old an offline check-in may be when it syncs. 0 = online only." />
        <x-mfield name="require_gps" as="toggle" label="GPS" helper="Require a GPS position" />
        <x-mfield name="require_selfie" as="toggle" label="Selfie" helper="Require a selfie" />
        <x-mfield name="reject_mock_location" as="toggle" label="Mock locations" helper="Reject mock (fake) GPS" />
        <x-mfield name="is_default" as="toggle" label="Default" helper="Company default policy" />
        @error('is_default')<p class="text-xs font-medium text-danger-ink sm:col-span-2">{{ $message }}</p>@enderror
    </form>
    <x-slot:footer>
        <x-button variant="outline" @click="hide()">Cancel</x-button>
        <x-button type="submit" form="policy-form">Save policy</x-button>
    </x-slot:footer>
</x-modal>
@endsection
