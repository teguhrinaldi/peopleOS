@extends('layouts.admin')
@php
    $editing = $employee !== null;
    $title = $editing ? 'Edit '.$employee->full_name : 'Add employee';
@endphp
@section('title', $title)

@section('content')
<x-page-header :title="$title" :subtitle="$editing ? $employee->employee_number : 'Create the employee record first; a login is optional.'"
    :breadcrumbs="[['Employees', route('admin.employees.index')], ...($editing ? [[$employee->full_name, route('admin.employees.show', $employee)], ['Edit']] : [['Add employee']])]" />

<form method="POST" enctype="multipart/form-data" novalidate
    action="{{ $editing ? route('admin.employees.update', $employee) : route('admin.employees.store') }}" class="space-y-6">
    @csrf
    @if ($editing) @method('PUT') @endif

    @if ($errors->any())
        <x-alert tone="danger" title="Please fix the highlighted fields.">Nothing was saved yet.</x-alert>
    @endif

    <x-form-section title="Personal" description="Who this person is. Shown on their profile and in the mobile app.">
        <div class="sm:col-span-2" x-data="{ preview: null }">
            <span class="mb-1.5 block text-[13px] font-medium text-ink">Photo</span>
            <div class="flex items-center gap-4">
                <template x-if="preview"><img :src="preview" alt="Selected photo preview" class="size-16 rounded-full object-cover ring-1 ring-line"></template>
                <div x-show="!preview"><x-avatar :name="$editing ? $employee->full_name : 'New employee'" :src="$editing ? $employee->photo_path : null" size="lg" /></div>
                <div class="min-w-0">
                    <input type="file" name="photo" id="f-photo" accept="image/jpeg,image/png,image/webp" class="block w-full text-[13px] text-ink-2 file:mr-3 file:cursor-pointer file:rounded-lg file:border-0 file:bg-primary-soft file:px-3.5 file:py-2 file:text-[13px] file:font-semibold file:text-primary-ink hover:file:bg-info-soft"
                        @change="preview = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : null">
                    <p class="mt-1.5 text-xs text-ink-3">JPG, PNG or WebP, up to 2 MB.</p>
                    @error('photo')<p class="mt-1.5 text-xs font-medium text-danger-ink">{{ $message }}</p>@enderror
                    @if ($editing && $employee->photo_path)
                        <x-checkbox name="remove_photo" label="Remove current photo" class="mt-2" />
                    @endif
                </div>
            </div>
        </div>
        <x-input name="full_name" label="Full name" :value="$employee?->full_name" required class="sm:col-span-2" autocomplete="off" />
        <x-select name="gender" label="Gender" :options="$options['genders']" :value="$employee?->gender?->value" placeholder="Not specified" />
        <x-input name="date_of_birth" type="date" label="Date of birth" :value="$employee?->date_of_birth?->toDateString()" />
        <x-input name="phone" type="tel" label="Phone" :value="$employee?->phone" placeholder="+62 812 3456 7890" />
        <x-input name="email" type="email" label="Email" :value="$employee?->email" placeholder="name@company.com" helper="Also used as the login email if you create an account." />
        <x-textarea name="address" label="Address" :value="$employee?->address" class="sm:col-span-2" />
    </x-form-section>

    <x-form-section title="Employment" description="Number, type and start date. The number must be unique in the company.">
        <x-input name="employee_number" label="Employee number" :value="$employee?->employee_number ?? ($options['suggested_number'] ?? null)" :placeholder="$options['suggested_number'] ?? 'EMP-0025'" required
            :helper="$editing ? null : 'Suggested from Settings → Employee numbers. You can change it.'" />
        <x-select name="employment_type" label="Employment type" :options="$options['types']" :value="$employee?->employment_type?->value ?? 'permanent'" required />
        <x-input name="join_date" type="date" label="Join date" :value="$employee?->join_date?->toDateString()" required />
        @unless ($editing)
            <x-select name="status" label="Starting status" :options="$options['statuses']" value="ACTIVE" required
                helper="Pending employees cannot check in or log in until you activate them." />
        @endunless
    </x-form-section>

    @if ($editing)
        <x-form-section title="Organization & assignment" description="Placement is history, so it is changed with a dated assignment instead of being overwritten.">
            <div class="sm:col-span-2">
                <x-kv :items="[
                    'Department' => $placement?->department?->name,
                    'Position' => $placement?->position?->name,
                    'Manager' => $placement?->manager?->full_name,
                    'Work location' => $placement?->workLocation?->name,
                    'Shift' => $placement?->shift?->name,
                ]" cols="2" />
                <x-button variant="outline" size="sm" class="mt-4" :href="route('admin.employees.show', [$employee, 'tab' => 'employment'])" icon="git-branch">Change assignment on the Employment tab</x-button>
            </div>
        </x-form-section>

        <x-form-section title="Account" description="The sign-in used by the mobile app and, for staff roles, this admin.">
            <div class="sm:col-span-2">
                @if ($employee->user)
                    <x-kv :items="[
                        'Login email' => $employee->user->email,
                        'Role' => $employee->user->roles->pluck('name')->join(', '),
                        'Login' => $employee->user->is_active ? 'Enabled' : 'Disabled',
                    ]" />
                    <p class="mt-3 text-xs text-ink-3">Login access follows the employee status: it is disabled when the employee is not active.</p>
                @else
                    <p class="text-[13px] text-ink-3">This employee has no login.</p>
                @endif
            </div>
        </x-form-section>
    @else
        <x-form-section title="Organization & assignment" description="Where the person sits. This becomes their first dated assignment.">
            <x-select name="department_id" label="Department" :options="$options['departments']" placeholder="Select department" required />
            <x-select name="position_id" label="Position" :options="$options['positions']" placeholder="Select position" required />
            <x-select name="manager_id" label="Manager" :options="$options['managers']" placeholder="No manager" />
            <x-select name="branch_id" label="Branch" :options="$options['branches']" placeholder="Use the department's branch" />
            <x-select name="work_location_id" label="Work location" :options="$options['locations']" placeholder="Not assigned" />
            <x-select name="shift_id" label="Shift" :options="$options['shifts']" placeholder="Not assigned" />
            <x-input name="effective_from" type="date" label="Effective from" helper="Leave empty to start on the join date." />
            <x-input name="assignment_reason" label="Note" placeholder="e.g. Initial assignment" />
        </x-form-section>

        <x-form-section title="Account" description="Optional. Gives the employee a login for the mobile app (and this admin, if you choose a staff role).">
            <div class="sm:col-span-2" x-data="{ login: @js((bool) old('create_login')) }">
                <x-toggle name="create_login" label="Create a login for this employee" helper="Uses the email above. Share the temporary password with them securely." x-model="login" />

                <div x-show="login" x-cloak class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-select name="role_slug" label="Role" :options="$options['roles']" placeholder="Select role" helper="Roles decide what they can open. You can only assign roles up to your own access." />
                    <div x-data="passwordField" class="sm:col-span-1">
                        <label for="f-password" class="mb-1.5 block text-[13px] font-medium text-ink">Temporary password</label>
                        <div class="flex gap-2">
                            <input id="f-password" name="password" x-model="value" :type="visible ? 'text' : 'password'" class="ctl" autocomplete="new-password" @error('password') aria-invalid="true" @enderror>
                            <x-button variant="outline" @click="generate()" class="shrink-0">Generate</x-button>
                        </div>
                        @error('password')<p class="mt-1.5 text-xs font-medium text-danger-ink">{{ $message }}</p>
                        @else<p class="mt-1.5 text-xs text-ink-3">At least 10 characters with letters and numbers.</p>@enderror
                    </div>
                </div>
            </div>
        </x-form-section>
    @endif

    <div class="sticky bottom-0 z-10 -mx-4 flex items-center justify-end gap-3 border-t border-line bg-surface/95 px-4 py-3 backdrop-blur sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8 no-print">
        <x-button variant="outline" :href="$editing ? route('admin.employees.show', $employee) : route('admin.employees.index')">Cancel</x-button>
        <x-button type="submit">{{ $editing ? 'Save changes' : 'Create employee' }}</x-button>
    </div>
</form>
@endsection
