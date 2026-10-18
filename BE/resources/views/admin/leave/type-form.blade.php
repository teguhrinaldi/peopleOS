@extends('layouts.admin')
@php
    use App\Enums\EmploymentType;
    use App\Support\LeaveColors;
    $editing = $type !== null;
    $title = $editing ? 'Edit '.$type->name : 'Add leave type';
    $color = old('color', $type?->color ?? 'blue');
    $employmentTypes = collect(EmploymentType::cases())->mapWithKeys(fn ($t) => [$t->value => \Illuminate\Support\Str::headline($t->value)])->all();
    $extra = $editing ? $type->policies->whereNotNull('employment_type')->sortBy('employment_type') : collect();
    $submittedEp = old('_form') === 'ep' ? old('employment_type') : null;
@endphp
@section('title', $title)

@section('content')
<x-page-header :title="$title" subtitle="Rules apply to new requests. Balances already created keep their allowance; policy edits affect new leave years."
    :breadcrumbs="[['Leave'], ['Leave Types', route('admin.leave-types')], [$editing ? $type->name : 'Add']]" />

<form method="POST" action="{{ $editing ? route('admin.leave-types.update', $type) : route('admin.leave-types.store') }}" novalidate class="space-y-6">
    @csrf
    @if ($editing) @method('PUT') @endif
    @if ($errors->any() && old('_form') !== 'ep')
        <x-alert tone="danger" title="Please fix the highlighted fields.">Nothing was saved yet.</x-alert>
    @endif

    <x-form-section title="Leave type" description="Shown to employees in the app and on every request.">
        <x-input name="name" label="Name" :value="$type?->name" placeholder="Annual Leave" required />
        <x-input name="code" label="Code" :value="$type?->code" placeholder="ANNUAL" required />
        <x-input name="description" label="Description" :value="$type?->description" class="sm:col-span-2" placeholder="Shown under the type name in the app" />
        <fieldset class="sm:col-span-2">
            <legend class="mb-1.5 block text-[13px] font-medium text-ink">Colour</legend>
            <div class="flex flex-wrap gap-2" role="radiogroup">
                @foreach (LeaveColors::options() as $token => $label)
                    <label class="cursor-pointer">
                        <input type="radio" name="color" value="{{ $token }}" class="peer sr-only" @checked($color === $token)>
                        <span class="flex items-center gap-2 rounded-full border border-line px-3 py-1.5 text-[13px] font-medium text-ink-2 transition peer-checked:border-primary peer-checked:bg-primary-soft peer-checked:text-primary-ink peer-focus-visible:outline-2 peer-focus-visible:outline-primary">
                            <span class="size-3.5 rounded-full {{ LeaveColors::solid($token) }}" aria-hidden="true"></span>{{ $label }}
                        </span>
                    </label>
                @endforeach
            </div>
            @error('color')<p class="mt-1.5 text-xs font-medium text-danger-ink">{{ $message }}</p>@enderror
        </fieldset>
        <x-toggle name="is_paid" label="Paid leave" helper="Unpaid leave is deducted by payroll." :checked="$type?->is_paid ?? true" />
        <x-toggle name="is_active" label="Active" :helper="$inUse ? 'In use: deactivate it to stop new requests; history keeps pointing at it.' : 'Inactive types cannot be requested.'" :checked="$type?->is_active ?? true" />
        <x-input name="sort_order" type="number" min="0" max="999" label="Sort order" :value="$type?->sort_order ?? 0" helper="Lower numbers are listed first." />
    </x-form-section>

    <x-form-section title="Default policy" description="Applies to everyone, unless their employment type has its own policy below.">
        @include('admin.leave._policy-fields', ['prefix' => 'policy', 'p' => $policy, 'live' => old('_form') !== 'ep', 'uid' => 'dp'])
    </x-form-section>

    <div class="sticky bottom-0 z-10 -mx-4 flex items-center justify-end gap-3 border-t border-line bg-surface/95 px-4 py-3 backdrop-blur sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8 no-print">
        @if ($editing && ! $inUse)
            <x-button variant="text" class="mr-auto text-danger-ink" icon="trash-2" :dispatch="['name' => 'confirm', 'data' => ['title' => 'Delete '.$type->name.'?', 'text' => 'This type has never been used, so it can be removed completely.', 'action' => route('admin.leave-types.destroy', $type), 'method' => 'DELETE', 'label' => 'Delete type']]">Delete</x-button>
        @endif
        <x-button variant="outline" :href="route('admin.leave-types')">Cancel</x-button>
        <x-button type="submit">{{ $editing ? 'Save leave type' : 'Create leave type' }}</x-button>
    </div>
</form>

@if ($editing)
    <x-card title="Employment-type policies" subtitle="Different rules for some employees, e.g. contract staff get fewer days. Everyone else follows the default." class="mt-6">
        <div class="space-y-3">
            @foreach ($extra as $p)
                @php $live = $submittedEp === $p->employment_type; @endphp
                <details class="group rounded-xl border border-line" @if ($live) open @endif>
                    <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-2 px-4 py-3">
                        <span class="font-semibold text-ink">{{ $employmentTypes[$p->employment_type] ?? $p->employment_type }}</span>
                        <span class="flex items-center gap-3 text-xs text-ink-2">
                            {{ $p->isUnlimited() ? 'Unlimited' : rtrim(rtrim((string) $p->annual_allowance, '0'), '.').' days / year' }}
                            <x-icon name="chevron-down" class="size-4 transition group-open:rotate-180" />
                        </span>
                    </summary>
                    <form method="POST" action="{{ route('admin.leave-types.policies.save', $type) }}" class="grid grid-cols-1 gap-4 border-t border-line p-4 sm:grid-cols-2">
                        @csrf
                        <input type="hidden" name="_form" value="ep">
                        <input type="hidden" name="employment_type" value="{{ $p->employment_type }}">
                        @include('admin.leave._policy-fields', ['prefix' => 'ep', 'p' => $p, 'live' => $live, 'uid' => 'ep'.$p->id])
                        <div class="flex justify-end gap-2 sm:col-span-2">
                            <x-button variant="text" class="text-danger-ink" :dispatch="['name' => 'confirm', 'data' => ['title' => 'Remove this policy?', 'text' => 'These employees will follow the default policy again.', 'action' => route('admin.leave-types.policies.destroy', [$type, $p]), 'method' => 'DELETE', 'label' => 'Remove policy']]">Remove</x-button>
                            <x-button type="submit" size="sm">Save policy</x-button>
                        </div>
                    </form>
                </details>
            @endforeach

            @php $free = array_diff_key($employmentTypes, $extra->pluck('employment_type')->flip()->all()); $newLive = $submittedEp !== null && ! $extra->contains('employment_type', $submittedEp); @endphp
            @if ($free)
                <details class="rounded-xl border border-dashed border-line-strong" @if ($newLive) open @endif>
                    <summary class="flex cursor-pointer list-none items-center gap-2 px-4 py-3 text-[13px] font-semibold text-primary-ink"><x-icon name="plus" class="size-4" />Add a policy for an employment type</summary>
                    <form method="POST" action="{{ route('admin.leave-types.policies.save', $type) }}" class="grid grid-cols-1 gap-4 border-t border-line p-4 sm:grid-cols-2">
                        @csrf
                        <input type="hidden" name="_form" value="ep">
                        <x-select name="employment_type" label="Employment type" :options="$free" placeholder="Select" required class="sm:col-span-2" />
                        @include('admin.leave._policy-fields', ['prefix' => 'ep', 'p' => $policy->replicate(), 'live' => $newLive, 'uid' => 'epnew'])
                        <div class="flex justify-end sm:col-span-2"><x-button type="submit" size="sm">Add policy</x-button></div>
                    </form>
                </details>
            @endif
        </div>
    </x-card>
@endif
@endsection
