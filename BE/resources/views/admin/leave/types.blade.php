@extends('layouts.admin')
@section('title', 'Leave types')

@section('content')
@php $num = fn ($v) => $v === null ? null : rtrim(rtrim((string) $v, '0'), '.'); @endphp
<x-page-header title="Leave Types" subtitle="Categories of leave and the rules behind them. Types with requests are retired (deactivated), never deleted."
    :breadcrumbs="[['Leave'], ['Leave Types']]">
    <x-button :href="route('admin.leave-types.create')" icon="plus">Add leave type</x-button>
</x-page-header>

<x-data-table :empty="$types->isEmpty()" emptyIcon="clipboard-list" emptyTitle="No leave types yet" emptyText="Add the kinds of leave your company offers, e.g. Annual, Sick, Unpaid." class="dt-sticky">
    <x-slot:head>
        <th>Leave type</th><th>Paid</th><th>Default allowance</th><th class="hidden md:table-cell">Rules</th>
        <th class="hidden md:table-cell">Policies</th><th class="text-right">Requests</th><th>Status</th><th class="w-24"><span class="sr-only">Actions</span></th>
    </x-slot:head>
    @foreach ($types as $type)
        @php
            $default = $type->policies->firstWhere('employment_type', null);
            $u = $usage[$type->id] ?? null;
            $total = (int) ($u->total ?? 0);
            $del = ['title' => 'Delete '.$type->name.'?', 'text' => 'This type has never been used, so it can be removed completely.', 'action' => route('admin.leave-types.destroy', $type), 'method' => 'DELETE', 'label' => 'Delete type'];
        @endphp
        <tr>
            <td>
                <a href="{{ route('admin.leave-types.edit', $type) }}" class="flex items-center gap-3">
                    <span class="size-3 shrink-0 rounded-full {{ \App\Support\LeaveColors::solid($type->color) }}" aria-hidden="true"></span>
                    <span><span class="block font-semibold text-ink hover:text-primary-ink">{{ $type->name }}</span><span class="block text-xs text-ink-3">{{ $type->code }}</span></span>
                </a>
            </td>
            <td>{{ $type->is_paid ? 'Paid' : 'Unpaid' }}</td>
            <td class="whitespace-nowrap font-semibold text-ink">{{ $default ? ($default->isUnlimited() ? 'Unlimited' : $num($default->annual_allowance).' days / year') : '—' }}</td>
            <td class="hidden text-xs md:table-cell">
                @if ($default)
                    {{ collect([$default->allow_half_day ? 'Half days' : null, $default->min_notice_days ? $default->min_notice_days.'d notice' : null, $default->max_past_days ? 'Back-date '.$default->max_past_days.'d' : null, $default->requires_reason ? 'Reason required' : null])->filter()->join(' · ') ?: 'No extra rules' }}
                @else <span class="text-danger-ink">No default policy</span> @endif
            </td>
            <td class="hidden md:table-cell">{{ $type->policies->count() }} <span class="text-xs text-ink-3">({{ max(0, $type->policies->count() - 1) }} by employment type)</span></td>
            <td class="text-right tabular-nums">{{ $total }}@if ((int) ($u->pending ?? 0))<span class="block text-xs text-warning-ink">{{ (int) $u->pending }} pending</span>@endif</td>
            <td><x-badge :status="$type->is_active ? 'active' : 'inactive'" /></td>
            <td class="whitespace-nowrap text-right">
                <x-button variant="text" size="sm" icon="pencil" :href="route('admin.leave-types.edit', $type)" :aria-label="'Edit '.$type->name" />
                @if ($total === 0)
                    <x-button variant="text" size="sm" icon="trash-2" class="text-danger-ink" :dispatch="['name' => 'confirm', 'data' => $del]" :aria-label="'Delete '.$type->name" />
                @endif
            </td>
        </tr>
    @endforeach
</x-data-table>
@endsection
