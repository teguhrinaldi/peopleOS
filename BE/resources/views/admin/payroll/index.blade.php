@extends('layouts.admin')
@section('title', 'Payroll')

@section('content')
@php
    use App\Services\Admin\PayrollDesk;
    use App\Support\DateDisplay;
    $user = auth()->user();
    $canManage = $user->hasPermission('payroll.manage');
    $today = app(\App\Support\CompanyContext::class)->today();
    $statuses = collect(\App\Enums\PayrollStatus::cases())->mapWithKeys(fn ($s) => [$s->value => ucfirst(strtolower($s->value))])->all();
    $active = collect($filters)->filter()->isNotEmpty();
    $createOpen = old('_form') === 'create-period';
@endphp

<x-page-header title="Payroll" subtitle="Monthly payroll runs and their approval workflow. Totals come from each run's own snapshot." :breadcrumbs="[['Payroll'], ['Payroll']]">
    @if ($canManage)
        <x-button icon="plus" :dispatch="['name' => 'create-period']">New period</x-button>
    @endif
</x-page-header>

<x-filter-panel :action="route('admin.payroll')" :active="$active" :reset="route('admin.payroll')" class="mb-4">
    <x-select name="status" label="Status" :options="$statuses" :value="$filters['status'] ?? null" placeholder="Any status" />
    <x-select name="type" label="Type" :options="['REGULAR' => 'Regular', 'ADJUSTMENT' => 'Adjustment']" :value="$filters['type'] ?? null" placeholder="All types" />
    <x-select name="year" label="Year" :options="array_combine($years, $years)" :value="$filters['year'] ?? null" placeholder="All years" />
</x-filter-panel>

<x-data-table :empty="$periods->isEmpty()" emptyIcon="wallet" :emptyTitle="$active ? 'No periods match' : 'No payroll periods yet'"
    :emptyText="$active ? 'Try another filter.' : 'Create a period, generate payroll from attendance, leave and salary data, then take it through review and approval.'" class="dt-sticky">
    @if (! $active && $canManage)
        <x-slot:emptyAction><x-button icon="plus" :dispatch="['name' => 'create-period']">Create the first period</x-button></x-slot:emptyAction>
    @endif
    <x-slot:head>
        <th>Period</th><th class="hidden md:table-cell">Dates</th><th>Status</th>
        <th class="hidden text-right lg:table-cell">Employees</th><th class="hidden text-right xl:table-cell">Gross</th>
        <th class="hidden text-right xl:table-cell">Deductions</th><th class="text-right">Net total</th><th class="w-10"><span class="sr-only">Open</span></th>
    </x-slot:head>
    @foreach ($periods as $p)
        @php $run = $p->run; @endphp
        <tr>
            <td>
                <a href="{{ route('admin.payroll.show', $p) }}" class="block font-semibold text-ink hover:text-primary-ink">{{ $p->name }}</a>
                <span class="text-xs text-ink-3">{{ $p->code }}@if ($p->isAdjustment()) &middot; <x-badge tone="info" :dot="false">Adjustment</x-badge>@endif</span>
            </td>
            <td class="hidden whitespace-nowrap md:table-cell">{{ DateDisplay::range($p->start_date, $p->end_date) }}@if ($p->pay_date)<span class="block text-xs text-ink-3">Pay date {{ DateDisplay::date($p->pay_date) }}</span>@endif</td>
            <td><x-badge :status="$p->status" /></td>
            <td class="hidden text-right tabular-nums lg:table-cell">{{ $run && $run->generated_at ? $run->employee_count : '—' }}
                @if ($run && $run->negative_net_count > 0)<span class="block text-xs font-semibold text-danger-ink">{{ $run->negative_net_count }} negative</span>@endif</td>
            <td class="hidden text-right tabular-nums xl:table-cell">{{ $run && $run->generated_at ? PayrollDesk::money($run->total_gross, $run, false) : '—' }}</td>
            <td class="hidden text-right tabular-nums xl:table-cell">{{ $run && $run->generated_at ? PayrollDesk::money($run->total_deductions, $run, false) : '—' }}</td>
            <td class="text-right font-semibold tabular-nums text-ink">{{ $run && $run->generated_at ? PayrollDesk::money($run->total_net, $run) : 'Not generated' }}</td>
            <td><a href="{{ route('admin.payroll.show', $p) }}" class="grid size-8 place-items-center rounded-lg text-ink-3 hover:bg-surface-3 hover:text-ink" aria-label="Open {{ $p->name }}"><x-icon name="chevron-right" class="size-4" /></a></td>
        </tr>
    @endforeach
    <x-slot:footer><x-pagination :paginator="$periods" /></x-slot:footer>
</x-data-table>

@if ($canManage)
    <x-modal name="create-period" title="New payroll period" description="A period covers up to 31 days and cannot overlap another period." :open="$createOpen">
        <form id="create-period-form" method="POST" action="{{ route('admin.payroll.store') }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @csrf
            <input type="hidden" name="_form" value="create-period">
            <x-input name="start_date" type="date" label="Start date" :value="$today->startOfMonth()->toDateString()" required />
            <x-input name="end_date" type="date" label="End date" :value="$today->endOfMonth()->toDateString()" required />
            <x-input name="name" label="Name" placeholder="Defaults to the month name" maxlength="120" class="sm:col-span-2" />
            <x-input name="pay_date" type="date" label="Pay date" helper="Optional. Shown on payslips." />
            <x-textarea name="notes" label="Notes" rows="2" maxlength="500" class="sm:col-span-2" />
        </form>
        <x-slot:footer>
            <x-button variant="outline" @click="hide()">Cancel</x-button>
            <x-button type="submit" form="create-period-form">Create draft period</x-button>
        </x-slot:footer>
    </x-modal>
@endif
@endsection
