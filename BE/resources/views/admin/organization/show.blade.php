@extends('layouts.admin')
@section('title', 'Organization')

@section('content')
@php
    $canManage = auth()->user()->hasPermission('organization.manage');
    $totals = $overview['totals'];
    $blank = ['id' => null, 'action' => route('admin.organization.branches.store'), 'title' => 'New branch', 'code' => '', 'name' => '', 'city' => '', 'address' => '', 'phone' => '', 'is_active' => true];
    $reopen = old('_form') === 'branch';
    $initial = $reopen ? [
        'id' => old('_id'), 'action' => old('_action'), 'title' => old('_id') ? 'Edit branch' : 'New branch',
        'code' => old('code'), 'name' => old('name'), 'city' => old('city'), 'address' => old('address'), 'phone' => old('phone'), 'is_active' => (bool) old('is_active'),
    ] : $blank;
    $zones = collect($timezones)->groupBy(fn ($z) => str_contains($z, '/') ? strstr($z, '/', true) : 'Other');
    $zoneNow = old('timezone', $company->timezone);
@endphp

<x-page-header title="Organization" subtitle="Company profile, branches and how people are spread across them." />

<section class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
    <x-stat-card label="Branches" :value="$totals['branches']" icon="building" tone="primary" />
    <x-stat-card label="Departments" :value="$totals['departments']" icon="network" tone="success" />
    <x-stat-card label="Positions" :value="$totals['positions']" icon="briefcase" tone="warning" />
    <x-stat-card label="People placed" :value="$totals['people']" icon="users" tone="primary" hint="Not terminated" />
</section>

<div class="grid grid-cols-1 gap-6 xl:grid-cols-5">
    <x-card title="Company profile" subtitle="Shown in the mobile app and on documents." class="xl:col-span-3">
        <form method="POST" action="{{ route('admin.organization.company') }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2" novalidate>
            @csrf @method('PUT')
            <x-input name="name" label="Company name" :value="$company->name" required class="sm:col-span-2" :disabled="! $canManage" />
            <x-input name="legal_name" label="Legal name" :value="$company->legal_name" class="sm:col-span-2" :disabled="! $canManage" />
            <x-input name="email" type="email" label="Contact email" :value="$company->email" :disabled="! $canManage" />
            <x-input name="phone" type="tel" label="Phone" :value="$company->phone" :disabled="! $canManage" />
            <div class="sm:col-span-2">
                <label for="f-timezone" class="mb-1.5 block text-[13px] font-medium text-ink">Time zone <span class="text-danger">*</span></label>
                <x-listbox><select id="f-timezone" name="timezone" class="ctl" @disabled(! $canManage) @error('timezone') aria-invalid="true" @enderror>
                    @foreach ($zones as $region => $list)
                        <optgroup label="{{ $region }}">
                            @foreach ($list as $zone)<option value="{{ $zone }}" @selected($zone === $zoneNow)>{{ $zone }}</option>@endforeach
                        </optgroup>
                    @endforeach
                </select></x-listbox>
                @error('timezone')<p class="mt-1.5 text-xs font-medium text-danger-ink">{{ $message }}</p>@else<p class="mt-1.5 text-xs text-ink-3">All attendance times are shown in this zone. Stored times are always UTC.</p>@enderror
            </div>
            <x-textarea name="address" label="Address" :value="$company->address" class="sm:col-span-2" :disabled="! $canManage" />
            @if ($canManage)
                <div class="flex justify-end sm:col-span-2"><x-button type="submit">Save company profile</x-button></div>
            @endif
        </form>
    </x-card>

    <x-card title="Structure" subtitle="Branches, departments and current headcount." class="xl:col-span-2">
        @if ($overview['branches']->isEmpty() && $overview['unplaced']->isEmpty())
            <p class="py-6 text-center text-[13px] text-ink-3">No branches or departments yet.</p>
        @else
            <ul class="space-y-4">
                @foreach ($overview['branches'] as $branch)
                    <li>
                        <div class="flex items-center justify-between gap-3 rounded-xl bg-surface-2 px-3.5 py-2.5">
                            <span class="flex min-w-0 items-center gap-2.5"><x-icon name="building-2" class="size-4 text-primary-ink" /><span class="truncate font-semibold text-ink">{{ $branch['name'] }}</span>@unless ($branch['active'])<x-badge status="inactive" :dot="false" />@endunless</span>
                            <span class="shrink-0 text-xs font-semibold text-ink-2">{{ $branch['headcount'] }} people</span>
                        </div>
                        @if ($branch['departments'])
                            <ul class="ml-4 mt-1 border-l border-line pl-3">
                                @foreach ($branch['departments'] as $node) @include('admin.organization.node', ['node' => $node]) @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
                @if ($overview['unplaced']->isNotEmpty())
                    <li>
                        <div class="rounded-xl bg-surface-2 px-3.5 py-2.5 text-[13px] font-semibold text-ink-2">Not tied to a branch</div>
                        <ul class="ml-4 mt-1 border-l border-line pl-3">
                            @foreach ($overview['unplaced'] as $node) @include('admin.organization.node', ['node' => $node]) @endforeach
                        </ul>
                    </li>
                @endif
            </ul>
        @endif
    </x-card>
</div>

<x-card id="branches" title="Branches" class="mt-6 scroll-mt-24" :padded="false">
    <x-slot:actions>
        @if ($canManage)<x-button size="sm" icon="plus" :dispatch="['name' => 'branch-form', 'data' => $blank]">Add branch</x-button>@endif
    </x-slot:actions>
    @if ($branches->isEmpty())
        <x-empty-state icon="building" title="No branches yet" text="Add your head office and other sites." />
    @else
        <div class="relative overflow-x-auto border-t border-line">
            <table class="dt">
                <thead><tr><th>Branch</th><th>City</th><th>Phone</th><th class="text-right">People</th><th>Status</th>@if ($canManage)<th class="w-24"><span class="sr-only">Actions</span></th>@endif</tr></thead>
                <tbody>
                    @foreach ($branches as $b)
                        @php
                            $count = $headcount[$b->id] ?? 0;
                            $edit = ['id' => $b->id, 'action' => route('admin.organization.branches.update', $b), 'title' => 'Edit branch', 'code' => $b->code, 'name' => $b->name, 'city' => (string) $b->city, 'address' => (string) $b->address, 'phone' => (string) $b->phone, 'is_active' => $b->is_active];
                            $del = ['title' => 'Delete '.$b->name.'?', 'text' => $count ? $b->name.' still has '.$count.' '.\Illuminate\Support\Str::plural('employee', $count).'. Reassign them first, or deactivate the branch instead.' : 'The branch will be removed. Departments or work locations still attached will block this.', 'action' => route('admin.organization.branches.destroy', $b), 'method' => 'DELETE', 'label' => 'Delete branch'];
                        @endphp
                        <tr>
                            <td><span class="block font-semibold text-ink">{{ $b->name }}</span><span class="block text-xs text-ink-3">{{ $b->code }}</span></td>
                            <td>{{ $b->city ?? '—' }}</td>
                            <td>{{ $b->phone ?? '—' }}</td>
                            <td class="text-right font-semibold tabular-nums text-ink">{{ $count }}</td>
                            <td><x-badge :status="$b->is_active ? 'active' : 'inactive'" /></td>
                            @if ($canManage)
                                <td class="whitespace-nowrap text-right">
                                    <x-button variant="text" size="sm" icon="pencil" :dispatch="['name' => 'branch-form', 'data' => $edit]" :aria-label="'Edit '.$b->name" />
                                    <x-button variant="text" size="sm" icon="trash-2" class="text-danger-ink" :dispatch="['name' => 'confirm', 'data' => $del]" :aria-label="'Delete '.$b->name" />
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-card>

@if ($canManage)
    <x-modal name="branch-form" :open="$reopen" :initial="$initial" title="Branch">
        <form id="branch-form" method="POST" :action="data.action" class="space-y-4">
            @csrf
            <input type="hidden" name="_form" value="branch">
            <input type="hidden" name="_id" :value="data.id">
            <input type="hidden" name="_action" :value="data.action">
            <template x-if="data.id"><input type="hidden" name="_method" value="PUT"></template>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <x-mfield name="code" label="Code" required placeholder="BDG" class="sm:col-span-1" />
                <x-mfield name="name" label="Name" required placeholder="Bandung Head Office" class="sm:col-span-2" />
            </div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-mfield name="city" label="City" />
                <x-mfield name="phone" type="tel" label="Phone" />
            </div>
            <x-mfield name="address" as="textarea" label="Address" :rows="2" />
            <x-mfield name="is_active" as="toggle" label="Status" helper="Active" />
        </form>
        <x-slot:footer>
            <x-button variant="outline" @click="hide()">Cancel</x-button>
            <x-button type="submit" form="branch-form">Save branch</x-button>
        </x-slot:footer>
    </x-modal>
@endif
@endsection
