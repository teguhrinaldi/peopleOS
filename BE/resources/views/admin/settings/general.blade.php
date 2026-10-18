@extends('layouts.admin')
@section('title', 'Settings')

@section('content')
@php
    $zones = collect($timezones)->groupBy(fn ($z) => str_contains($z, '/') ? strstr($z, '/', true) : 'Other');
    $zoneNow = old('timezone', $company->timezone);
@endphp

<x-page-header title="Settings" subtitle="Company-wide configuration. Changes are recorded in the activity log." :breadcrumbs="[['Settings']]" />

@include('admin.settings._tabs', ['active' => 'general'])

<form method="POST" action="{{ route('admin.settings.update') }}" enctype="multipart/form-data" novalidate class="space-y-6">
    @csrf @method('PUT')

    @if ($errors->any())
        <x-alert tone="danger" title="Please fix the highlighted fields.">Nothing was saved yet.</x-alert>
    @endif

    <x-form-section title="Company" description="Shown in the mobile app, on the sign-in page and on documents.">
        <x-input name="name" label="Company name" :value="$company->name" required class="sm:col-span-2" />
        <x-textarea name="address" label="Address" :value="$company->address" class="sm:col-span-2" :rows="2" />
        <div class="sm:col-span-2" x-data="{ preview: null }">
            <span class="mb-1.5 block text-[13px] font-medium text-ink">Logo</span>
            <div class="flex items-center gap-4">
                <span class="grid size-16 shrink-0 place-items-center overflow-hidden rounded-xl bg-surface-2 ring-1 ring-line">
                    <template x-if="preview"><img :src="preview" alt="New logo preview" class="size-full object-contain"></template>
                    @if ($company->logo_path)
                        <img x-show="!preview" src="{{ asset('storage/'.$company->logo_path) }}" alt="{{ $company->name }} logo" class="size-full object-contain">
                    @else
                        <x-icon x-show="!preview" name="building" class="size-6 text-ink-3" />
                    @endif
                </span>
                <div class="min-w-0">
                    <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" aria-label="Upload logo"
                        class="block w-full text-[13px] text-ink-2 file:mr-3 file:cursor-pointer file:rounded-lg file:border-0 file:bg-primary-soft file:px-3.5 file:py-2 file:text-[13px] file:font-semibold file:text-primary-ink"
                        @change="preview = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : null">
                    <p class="mt-1.5 text-xs text-ink-3">PNG, JPG or WebP, up to 1 MB and 2000 × 2000 px.</p>
                    @error('logo')<p class="mt-1.5 text-xs font-medium text-danger-ink">{{ $message }}</p>@enderror
                    @if ($company->logo_path)<x-checkbox name="remove_logo" label="Remove current logo" class="mt-2" />@endif
                </div>
            </div>
        </div>
    </x-form-section>

    <x-form-section title="Time zone" description="Attendance days, late minutes and every time on screen use this zone. Stored times stay in UTC.">
        <div class="sm:col-span-2">
            <label for="f-timezone" class="mb-1.5 block text-[13px] font-medium text-ink">Time zone <span class="text-danger">*</span></label>
            <x-listbox><select id="f-timezone" name="timezone" class="ctl" @error('timezone') aria-invalid="true" @enderror>
                @foreach ($zones as $region => $list)
                    <optgroup label="{{ $region }}">
                        @foreach ($list as $zone)<option value="{{ $zone }}" @selected($zone === $zoneNow)>{{ $zone }}</option>@endforeach
                    </optgroup>
                @endforeach
            </select></x-listbox>
            @error('timezone')<p class="mt-1.5 text-xs font-medium text-danger-ink">{{ $message }}</p>
            @else<p class="mt-1.5 text-xs text-ink-3">Company time now: <span class="font-semibold text-ink">{{ $now->format('D, d M Y H:i') }}</span> ({{ $company->timezone }}).</p>@enderror
        </div>
        <x-alert tone="warning" class="sm:col-span-2">Changing the zone moves where each company day starts and ends. Schedules already recorded keep the zone they were captured in.</x-alert>
    </x-form-section>

    <x-form-section title="Regional" description="How money and dates are shown across the admin and documents.">
        <x-select name="currency" label="Currency" :options="$currencies" :value="$values['general.currency']" required helper="ISO 4217 code used by payroll and payslips." />
        <x-select name="date_format" label="Date format" :options="collect($dateFormats)->mapWithKeys(fn ($example, $format) => [$format => $example])->all()" :value="$values['general.date_format']" required />
    </x-form-section>

    <x-form-section title="Employee numbers" description="Used to suggest the next number when you add an employee. The number can still be changed by hand.">
        <div x-data="{ prefix: @js(old('employee_number_prefix', $values['employee.number_prefix'])), digits: @js((int) old('employee_number_digits', $values['employee.number_digits'])) }" class="contents">
            <div>
                <label for="f-employee_number_prefix" class="mb-1.5 block text-[13px] font-medium text-ink">Prefix</label>
                <input id="f-employee_number_prefix" name="employee_number_prefix" x-model="prefix" maxlength="10" class="ctl uppercase" @error('employee_number_prefix') aria-invalid="true" @enderror>
                @error('employee_number_prefix')<p class="mt-1.5 text-xs font-medium text-danger-ink">{{ $message }}</p>@else<p class="mt-1.5 text-xs text-ink-3">May be empty. Letters, digits and . _ / -.</p>@enderror
            </div>
            <div>
                <label for="f-employee_number_digits" class="mb-1.5 block text-[13px] font-medium text-ink">Digits <span class="text-danger">*</span></label>
                <input id="f-employee_number_digits" type="number" min="1" max="10" name="employee_number_digits" x-model.number="digits" class="ctl" @error('employee_number_digits') aria-invalid="true" @enderror>
                @error('employee_number_digits')<p class="mt-1.5 text-xs font-medium text-danger-ink">{{ $message }}</p>@else
                <p class="mt-1.5 text-xs text-ink-3">Example: <span class="font-mono font-semibold text-ink" x-text="(prefix || '').toUpperCase() + String(42).padStart(Math.min(10, Math.max(1, digits || 1)), '0')">{{ $exampleNumber }}</span></p>@enderror
            </div>
        </div>
    </x-form-section>

    <x-form-section title="Leave" description="Leave years, balances and carry-over are counted from this month.">
        <x-select name="leave_year_start_month" label="Leave year starts in" :value="$values['leave.year_start_month']" required
            :options="collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => \Carbon\Carbon::create(2026, $m, 1)->format('F')])->all()"
            helper="Change this only between leave years: balances are kept per leave year." />
    </x-form-section>

    <x-form-section title="Payroll" description="Used by the Payroll module when runs are prepared and approved.">
        <x-toggle name="payroll_require_separate_approver" label="Separate approver" helper="The person who prepares a payroll run cannot also approve it." :checked="$values['payroll.require_separate_approver']" class="sm:col-span-2" />
        <x-input name="payslip_prefix" label="Payslip number prefix" :value="$values['payroll.payslip_prefix']" maxlength="10" required helper="e.g. PS → PS-2026-10-0001" />
        <x-select name="payroll_rounding_decimals" label="Rounding" :value="$values['payroll.rounding_decimals']" required
            :options="['auto' => 'By currency (IDR: 0, USD: 2…)', '0' => 'Whole units', '1' => '1 decimal', '2' => '2 decimals']" />
    </x-form-section>

    <x-form-section title="Documents" description="When a document starts showing as expiring soon.">
        <x-input name="documents_expiring_days" type="number" min="1" max="365" label="Warn this many days before expiry" :value="$values['documents.expiring_days']" required />
    </x-form-section>

    <div class="sticky bottom-0 z-10 -mx-4 flex items-center justify-end gap-3 border-t border-line bg-surface/95 px-4 py-3 backdrop-blur sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8 no-print">
        <x-button type="submit">Save settings</x-button>
    </div>
</form>

<x-card id="attendance" title="Attendance defaults" subtitle="Locations without their own policy use the default attendance policy." class="mt-6 scroll-mt-24">
    @if ($defaultPolicy)
        <div class="flex flex-wrap items-start justify-between gap-4">
            <dl class="grid grid-cols-2 gap-x-8 gap-y-2 text-[13px] sm:grid-cols-4">
                <div><dt class="text-xs text-ink-3">Default policy</dt><dd class="font-semibold text-ink">{{ $defaultPolicy->name }}</dd></div>
                <div><dt class="text-xs text-ink-3">Geofence</dt><dd class="font-semibold text-ink">{{ ucfirst($defaultPolicy->geofence_mode->value) }}</dd></div>
                <div><dt class="text-xs text-ink-3">GPS</dt><dd class="font-semibold text-ink">{{ $defaultPolicy->require_gps ? 'Required' : 'Optional' }}</dd></div>
                <div><dt class="text-xs text-ink-3">Offline window</dt><dd class="font-semibold text-ink">{{ $defaultPolicy->offline_max_age_minutes }} min</dd></div>
            </dl>
            <x-button variant="outline" size="sm" icon="shield-check" :href="route('admin.work-locations.policies')">Edit attendance policies</x-button>
        </div>
        <form method="POST" action="{{ route('admin.settings.attendance') }}" class="mt-5 flex flex-wrap items-center justify-between gap-4 rounded-xl bg-surface-2 p-4">
            @csrf @method('PUT')
            <x-toggle name="require_selfie" label="Selfie required at check-in" helper="Applies to the default policy; locations with their own policy keep their rule." :checked="$defaultPolicy->require_selfie" class="min-w-0 flex-1" />
            <x-button type="submit" size="sm">Save</x-button>
        </form>
    @else
        <p class="text-[13px] text-ink-3">There is no default attendance policy yet.</p>
        <x-button class="mt-3" variant="outline" size="sm" :href="route('admin.work-locations.policies')">Create one</x-button>
    @endif
</x-card>
@endsection
