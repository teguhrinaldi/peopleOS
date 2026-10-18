@extends('layouts.admin')
@php
    $editing = $location !== null;
    $title = $editing ? 'Edit '.$location->name : 'Add work location';
@endphp
@section('title', $title)

@section('content')
<x-page-header :title="$title" subtitle="Check-ins are accepted inside the radius (or flagged for review, depending on the policy)."
    :breadcrumbs="[['Attendance'], ['Work Locations', route('admin.work-locations')], [$editing ? $location->name : 'Add']]" />

@if ($editing)
    <x-alert tone="info" class="mb-6">Changing coordinates, radius or policy affects check-ins from now on. Attendance already recorded keeps the geofence it was captured with.</x-alert>
@endif

<form method="POST" action="{{ $editing ? route('admin.work-locations.update', $location) : route('admin.work-locations.store') }}" novalidate
    class="grid grid-cols-1 gap-6 xl:grid-cols-[1fr_340px]" x-data="{ radius: @js((int) old('radius_meters', $location?->radius_meters ?? 100)) }">
    @csrf
    @if ($editing) @method('PUT') @endif

    <div class="space-y-6">
        @if ($errors->any())
            <x-alert tone="danger" title="Please fix the highlighted fields.">Nothing was saved yet.</x-alert>
        @endif

        <x-form-section title="Location" description="Name and code appear on schedules and in the mobile app.">
            <x-input name="name" label="Name" :value="$location?->name" placeholder="Head Office" required />
            <x-input name="code" label="Code" :value="$location?->code" placeholder="HO" required />
            <x-select name="branch_id" label="Branch" :options="$branches" :value="$location?->branch_id" placeholder="No branch" />
            <x-select name="attendance_policy_id" label="Attendance policy" :options="$policies" :value="$location?->attendance_policy_id" placeholder="Use the default policy" />
            <x-textarea name="address" label="Address" :value="$location?->address" class="sm:col-span-2" :rows="2" />
        </x-form-section>

        <x-form-section title="Geofence" description="Decimal degrees (WGS84). Copy them from any map app; no map service is contacted from here.">
            <x-input name="latitude" type="number" step="0.0000001" min="-90" max="90" label="Latitude" :value="$location?->latitude" placeholder="-6.9218" required />
            <x-input name="longitude" type="number" step="0.0000001" min="-180" max="180" label="Longitude" :value="$location?->longitude" placeholder="107.6071" required />
            <div class="sm:col-span-2">
                <label for="f-radius_meters" class="mb-1.5 block text-[13px] font-medium text-ink">Radius (metres) <span class="text-danger">*</span></label>
                <div class="flex items-center gap-4">
                    <input type="range" min="10" max="1000" step="10" x-model="radius" class="h-2 flex-1 cursor-pointer accent-[var(--primary)]" aria-label="Radius slider">
                    <input id="f-radius_meters" type="number" name="radius_meters" min="10" max="5000" x-model="radius" class="ctl w-28" @error('radius_meters') aria-invalid="true" @enderror required>
                </div>
                @error('radius_meters')<p class="mt-1.5 text-xs font-medium text-danger-ink">{{ $message }}</p>
                @else<p class="mt-1.5 text-xs text-ink-3">10 – 5,000 m. Keep it just larger than the building plus typical GPS error.</p>@enderror
            </div>
            <x-toggle name="is_active" label="Active" helper="Inactive locations cannot be assigned to anyone new." :checked="$location?->is_active ?? true" class="sm:col-span-2" />
        </x-form-section>
    </div>

    <aside class="space-y-4">
        <x-card title="Geofence preview">
            <x-geo.radius :radius="$location?->radius_meters ?? 100" live />
        </x-card>
    </aside>

    <div class="sticky bottom-0 z-10 -mx-4 flex items-center justify-end gap-3 border-t border-line bg-surface/95 px-4 py-3 backdrop-blur sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8 xl:col-span-2 no-print">
        <x-button variant="outline" :href="route('admin.work-locations')">Cancel</x-button>
        <x-button type="submit">{{ $editing ? 'Save location' : 'Create location' }}</x-button>
    </div>
</form>
@endsection
