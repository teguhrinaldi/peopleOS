@extends('layouts.admin')
@section('title', $period->name)

@section('content')
@php
    use App\Enums\PayrollStatus as S;
    use App\Services\Admin\PayrollDesk;
    use App\Support\DateDisplay;
    $user = auth()->user();
    $status = $period->status;
    $locked = $status->isLocked();
    $cancelled = $status === S::Cancelled;
    $processing = $status === S::Processing;
    $money = fn ($v, $cur = true) => PayrollDesk::money($v, $run, $cur);
    $summary = $run?->summary ?? [];
    $skipped = $summary['skipped'] ?? [];
    $runWarnings = $summary['warnings'] ?? [];
    $generated = $run && $run->generated_at;
    $skipReasons = ['NO_SALARY_COMPONENTS' => 'No salary set up', 'EMPLOYEE_INACTIVE' => 'Employee is inactive', 'EMPLOYEE_PENDING' => 'Employee is pending activation'];
    $warnLabels = ['NEGATIVE_NET' => 'Negative net pay', 'NO_BASIC_SALARY' => 'No basic salary', 'NO_WORKING_DAYS' => 'No working days in period'];
    $total = $run?->total_net;

    $modals = [
        'approve' => ['title' => 'Approve '.$period->name.'?', 'text' => 'You confirm the totals and warnings were reviewed. Approval does not lock anything yet: the next step is to finalize. If separate approval is required, you cannot approve payroll you generated or submitted.', 'label' => 'Approve payroll', 'danger' => false],
        'return_to_draft' => ['title' => 'Return to draft', 'text' => 'The approval or review is withdrawn. Generate the payroll again if data changed, then submit it for review again.', 'label' => 'Return to draft', 'danger' => false, 'reason' => true],
        'finalize' => ['title' => 'Finalize '.$period->name.'?', 'text' => 'This cannot be undone. Payroll becomes read-only, payslip numbers are issued, PDFs are generated and employees are notified. Later corrections need an adjustment period.', 'label' => 'Finalize payroll', 'danger' => true],
        'mark_paid' => ['title' => 'Mark as paid?', 'text' => 'This cannot be undone. It records that salaries were paid. Only do it once the bank transfer is completed.', 'label' => 'Mark as paid', 'danger' => true, 'ref' => true],
        'cancel' => ['title' => 'Cancel '.$period->name.'?', 'text' => 'The period is cancelled for good and cannot be reopened. Nothing is deleted: the record and your reason stay in the audit trail. Create a new period to run payroll again.', 'label' => 'Cancel period', 'danger' => true, 'reason' => true],
    ];
    $routeNames = ['approve' => 'approve', 'return_to_draft' => 'return-to-draft', 'finalize' => 'finalize', 'mark_paid' => 'mark-paid', 'cancel' => 'cancel'];
    foreach ($modals as $key => &$m) { $m['key'] = $key; $m['action'] = route('admin.payroll.'.$routeNames[$key], $period); } unset($m);
    $reopenKey = old('_form') === 'payroll-action' ? old('_key') : null;
    $initial = $reopenKey && isset($modals[$reopenKey]) ? $modals[$reopenKey] + ['reasonValue' => old('reason', ''), 'refValue' => old('payment_reference', '')] : [];
    $adjustOpen = old('_form') === 'adjustment';
    $entryOpen = old('_form') === 'entry';
    $editOpen = old('_form') === 'edit-period';
    $canAdjust = $canManage && ! $period->isAdjustment() && $locked;
@endphp

<x-page-header :title="$period->name" :breadcrumbs="[['Payroll', route('admin.payroll')], [$period->name]]"
    :subtitle="DateDisplay::range($period->start_date, $period->end_date).($period->pay_date ? ' · pay date '.DateDisplay::date($period->pay_date) : '')">
    <x-badge :status="$status" />
    @if ($period->isAdjustment())<x-badge tone="info" :dot="false">Adjustment</x-badge>@endif
    @foreach ($actions as $a)
        @if (in_array($a['action'], ['generate', 'submit'], true))
            <form method="POST" action="{{ route('admin.payroll.'.$a['action'], $period) }}" data-submit-spinner>@csrf
                <x-button type="submit" :variant="$a['variant']" :icon="$a['icon']">{{ $a['label'] }}</x-button>
            </form>
        @else
            <x-button :variant="$a['variant']" :icon="$a['icon']" :dispatch="['name' => 'payroll-action', 'data' => $modals[$a['action']]]">{{ $a['label'] }}</x-button>
        @endif
    @endforeach
    @if ($canAdjust)
        <x-button variant="outline" icon="file-plus" :dispatch="['name' => 'adjustment']">Create adjustment period</x-button>
    @endif
    @if ($canManage && ! $locked && ! $cancelled)
        <x-button variant="outline" icon="pencil" :dispatch="['name' => 'edit-period']">Edit details</x-button>
    @endif
</x-page-header>

{{-- Workflow stepper --}}
<x-card class="mb-6" aria-label="Payroll workflow">
    @if ($cancelled)
        <x-alert tone="danger" title="This period was cancelled" icon="ban">
            {{ $run?->cancel_reason ?? 'No reason recorded.' }}
            <span class="mt-1 block text-xs">Cancelled {{ DateDisplay::moment($run?->cancelled_at) }}. It cannot be reopened; create a new period to run payroll again.</span>
        </x-alert>
    @else
        <ol class="grid grid-cols-5 gap-1 sm:gap-3" aria-label="Workflow progress">
            @foreach ($steps as $i => $step)
                <li class="relative flex flex-col items-center text-center" @if ($step['state'] === 'current') aria-current="step" @endif>
                    @unless ($loop->last)
                        <span class="absolute left-1/2 top-4 -z-0 h-0.5 w-full {{ $step['state'] === 'done' ? 'bg-primary' : 'bg-line' }}" aria-hidden="true"></span>
                    @endunless
                    <span class="relative z-10 grid size-8 place-items-center rounded-full text-[13px] font-bold ring-4 ring-surface
                        {{ $step['state'] === 'done' ? 'bg-primary text-white' : ($step['state'] === 'current' ? 'bg-primary-soft text-primary-ink ring-offset-0 outline outline-2 outline-primary' : 'bg-surface-3 text-ink-3') }}">
                        @if ($step['state'] === 'done')<x-icon name="check" class="size-4" />@else{{ $i + 1 }}@endif
                    </span>
                    <span class="mt-2 text-[11.5px] font-semibold sm:text-[12.5px] {{ $step['state'] === 'upcoming' ? 'text-ink-3' : 'text-ink' }}">{{ $step['label'] }}<span class="sr-only left-0"> ({{ $step['state'] === 'done' ? 'completed' : ($step['state'] === 'current' ? 'current step' : 'not reached') }})</span></span>
                </li>
            @endforeach
        </ol>
        @if ($processing)
            <p class="mt-4 flex items-center gap-2 rounded-lg bg-info-soft px-3 py-2 text-[13px] text-info-ink" role="status"><x-icon name="refresh-cw" class="size-4 animate-spin" />Payroll is being generated. Refresh this page in a moment; actions are paused until it finishes.</p>
        @endif
    @endif
</x-card>

@if ($locked)
    <x-alert tone="info" icon="lock" class="mb-6" :title="$status === S::Paid ? 'Paid and locked' : 'Finalized and locked'">
        This payroll is read-only: amounts, items and payslips can no longer be edited or deleted.
        @if ($canAdjust) To correct a mistake, create an adjustment period; the original stays untouched.@endif
    </x-alert>
@endif

@if ($run && $run->return_reason && $status === S::Draft)
    <x-alert tone="warning" icon="undo-2" title="Returned to draft" class="mb-6">{{ $run->return_reason }}</x-alert>
@endif

@if ($period->original)
    <x-alert tone="info" class="mb-6" title="Adjustment period">
        Corrects <a href="{{ route('admin.payroll.show', $period->original) }}" class="font-semibold underline">{{ $period->original->name }}</a>@if ($period->adjustment_reason): {{ $period->adjustment_reason }}@endif.
    </x-alert>
@endif

{{-- Totals from the run snapshot --}}
@if ($generated)
    <section class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Run totals">
        @foreach ([['Employees', (string) $run->employee_count, 'users', 'primary', ($summary['eligible'] ?? $run->employee_count).' eligible'], ['Gross pay (' . $run->currency . ')', $money($run->total_gross, false), 'wallet', 'primary', null], ['Deductions (' . $run->currency . ')', $money($run->total_deductions, false), 'minus', 'warning', null], ['Net pay (' . $run->currency . ')', $money($run->total_net, false), 'banknote', 'success', $run->negative_net_count ? $run->negative_net_count.' negative' : null]] as [$label, $value, $icon, $tone, $hint])
            <x-stat-card :label="$label" :value="$value" :icon="$icon" :tone="$tone" :hint="$hint" :hintTone="$hint && str_contains($hint, 'negative') ? 'danger' : null" class="[&_.text-2xl]:text-xl" />
        @endforeach
    </section>
@endif

<div class="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-5">
    <x-card title="Run summary" subtitle="What this run was calculated from" class="lg:col-span-3">
        @if (! $generated)
            <x-empty-state icon="refresh-cw" title="Not generated yet" class="py-8"
                :text="$cancelled ? 'This period was cancelled before it was generated.' : 'Generate payroll to calculate pay for every eligible employee from attendance, approved leave, overtime and salary history.'" />
        @else
            <x-kv cols="2" :items="[
                'Currency' => $run->currency.' ('.$run->decimals.' decimals)',
                'Working days' => $summary['working_days'] ?? null,
                'Eligible employees' => $summary['eligible'] ?? null,
                'Included' => $summary['included'] ?? $run->employee_count,
                'Generated' => $run->generated_at ? DateDisplay::moment($run->generated_at).' by '.($run->generatedBy?->name ?? '—') : null,
                'Submitted' => $run->submitted_at ? DateDisplay::moment($run->submitted_at).' by '.($run->submittedBy?->name ?? '—') : null,
                'Approved' => $run->approved_at ? DateDisplay::moment($run->approved_at).' by '.($run->approvedBy?->name ?? '—') : null,
                'Finalized' => $run->finalized_at ? DateDisplay::moment($run->finalized_at).' by '.($run->finalizedBy?->name ?? '—') : null,
                'Paid' => $run->paid_at ? DateDisplay::moment($run->paid_at).' by '.($run->paidBy?->name ?? '—') : null,
                'Payment reference' => $run->payment_reference,
            ]" />
        @endif
        @if ($period->notes)<p class="mt-4 border-t border-line pt-3 text-[13px] text-ink-2"><span class="text-ink-3">Notes:</span> {{ $period->notes }}</p>@endif
    </x-card>

    <x-card title="Warnings" subtitle="Check these before approving" class="lg:col-span-2">
        @if (! $generated)
            <p class="text-[13px] text-ink-3">Warnings appear after payroll is generated.</p>
        @else
            @if ($run->negative_net_count > 0)
                <x-alert tone="danger" class="mb-3" :title="$run->negative_net_count.' employee(s) with negative net pay'">Approval is blocked until this is fixed. Filter the table below for the red rows, correct their salary or deductions, then generate again.</x-alert>
            @endif
            @foreach ($runWarnings as $w)
                <x-alert tone="warning" class="mb-3">{{ is_array($w) ? ($w['message'] ?? $w['code'] ?? 'Warning') : $w }}</x-alert>
            @endforeach
            @if ($skipped)
                <p class="mb-2 text-[13px] font-semibold text-ink">{{ count($skipped) }} employee(s) were skipped</p>
                <ul class="max-h-52 divide-y divide-line overflow-y-auto rounded-lg border border-line text-[13px]">
                    @foreach ($skipped as $s)
                        <li class="flex items-start justify-between gap-3 px-3 py-2"><span class="min-w-0"><span class="block truncate font-medium text-ink">{{ $s['employee_name'] ?? '—' }}</span><span class="text-xs text-ink-3">{{ $s['employee_number'] ?? '' }}</span></span>
                            <x-badge tone="warning" :dot="false">{{ $skipReasons[$s['reason'] ?? ''] ?? ($s['reason'] ?? 'Skipped') }}</x-badge></li>
                    @endforeach
                </ul>
            @endif
            @if ($run->negative_net_count === 0 && ! $runWarnings && ! $skipped)
                <p class="flex items-center gap-2 text-[13px] text-success-ink"><x-icon name="circle-check" class="size-4" />Nothing to flag. Every eligible employee was included.</p>
            @endif
        @endif
    </x-card>
</div>

@if ($period->isAdjustment())
    <x-card title="Adjustment entries" subtitle="Manual corrections for employees of the original payroll" class="mb-6">
        <x-slot:actions>
            @if ($canManage && $desk->canEditEntries($period))<x-button size="sm" icon="plus" :dispatch="['name' => 'entry']">Add entry</x-button>@endif
        </x-slot:actions>
        @if ($entries->isEmpty())
            <p class="py-4 text-center text-[13px] text-ink-3">No entries yet. @if ($desk->canEditEntries($period)) Add the earnings or deductions to correct, then generate. @endif</p>
        @else
            <div class="relative -mx-5 overflow-x-auto"><table class="dt">
                <thead><tr><th>Employee</th><th>Entry</th><th class="text-right">Amount</th><th class="hidden md:table-cell">Reason</th>@if ($desk->canEditEntries($period))<th class="w-10"><span class="sr-only">Remove</span></th>@endif</tr></thead>
                <tbody>
                @foreach ($entries as $e)
                    <tr>
                        <td><span class="font-medium text-ink">{{ $e->employee?->full_name }}</span><span class="block text-xs text-ink-3">{{ $e->employee?->employee_number }}</span></td>
                        <td>{{ $e->label }} <x-badge :tone="$e->kind->value === 'EARNING' ? 'success' : 'warning'" :dot="false">{{ ucfirst(strtolower($e->kind->value)) }}</x-badge></td>
                        <td class="text-right font-semibold tabular-nums">{{ $money($e->amount) }}</td>
                        <td class="hidden max-w-xs truncate md:table-cell" title="{{ $e->reason }}">{{ $e->reason }}</td>
                        @if ($desk->canEditEntries($period))
                            <td>@if ($canManage)<x-button variant="text" size="sm" icon="trash-2" class="text-danger-ink" aria-label="Remove entry" :dispatch="['name' => 'confirm', 'data' => ['title' => 'Remove this entry?', 'text' => 'The entry is removed from the adjustment. Generate the adjustment payroll again afterwards.', 'action' => route('admin.payroll.entries.destroy', [$period, $e]), 'method' => 'DELETE', 'label' => 'Remove entry']]" />@endif</td>
                        @endif
                    </tr>
                @endforeach
                </tbody></table></div>
        @endif
    </x-card>
@endif

@if (! $period->isAdjustment() && $period->adjustmentPeriods->isNotEmpty())
    <x-card title="Adjustment periods" subtitle="Corrections made after this payroll was finalized" class="mb-6">
        <ul class="divide-y divide-line">
            @foreach ($period->adjustmentPeriods as $adj)
                <li class="flex items-center justify-between gap-3 py-2.5 first:pt-0 last:pb-0"><a href="{{ route('admin.payroll.show', $adj) }}" class="font-semibold text-primary-ink hover:underline">{{ $adj->name }}</a><x-badge :status="$adj->status" /></li>
            @endforeach
        </ul>
    </x-card>
@endif

{{-- Items --}}
@if ($generated && $items)
    <section aria-labelledby="items-h">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <h2 id="items-h" class="text-[15px] font-semibold text-ink">Employee pay <span class="font-normal text-ink-3">({{ $items->total() }})</span></h2>
            <x-filter-bar :action="route('admin.payroll.show', $period)" :reset="route('admin.payroll.show', $period)" :q="$q" placeholder="Search employee or department" :active="$q !== ''" />
        </div>
        <x-data-table :empty="$items->isEmpty()" emptyIcon="users" :emptyTitle="$q !== '' ? 'No employees match' : 'No employees in this run'" :emptyText="$q !== '' ? 'Try another name, number or department.' : 'Every eligible employee was skipped. See the warnings above.'" class="dt-sticky">
            <x-slot:head>
                <th class="sticky left-0 z-[1] bg-surface">Employee</th><th class="hidden md:table-cell">Department</th><th class="hidden text-right lg:table-cell">Present</th>
                <th class="text-right">Gross</th><th class="hidden text-right md:table-cell">Deductions</th><th class="text-right">Net</th><th class="hidden sm:table-cell">Warnings</th><th class="w-10"><span class="sr-only">Details</span></th>
            </x-slot:head>
            @foreach ($items as $it)
                <tr class="{{ $it->has_negative_net ? 'bg-danger-soft/40' : '' }}">
                    <td class="sticky left-0 z-[1] bg-surface">
                        <button type="button" class="text-left" @click="$dispatch('open-payroll-item', @js(['url' => route('admin.payroll.items.show', [$period, $it]), 'title' => $it->employee_name]))">
                            <span class="block font-semibold text-ink hover:text-primary-ink">{{ $it->employee_name }}</span><span class="text-xs text-ink-3">{{ $it->employee_number }}</span>
                        </button>
                    </td>
                    <td class="hidden md:table-cell">{{ $it->department_name ?? '—' }}</td>
                    <td class="hidden text-right tabular-nums lg:table-cell">{{ $it->present_days }}<span class="text-ink-3"> / {{ $it->working_days }}</span></td>
                    <td class="text-right tabular-nums">{{ $money($it->gross_pay, false) }}</td>
                    <td class="hidden text-right tabular-nums md:table-cell">{{ $money($it->total_deductions, false) }}</td>
                    <td class="text-right font-semibold tabular-nums {{ $it->has_negative_net ? 'text-danger-ink' : 'text-ink' }}">{{ $money($it->net_pay, false) }}</td>
                    <td class="hidden sm:table-cell">
                        @forelse ((array) $it->warnings as $w)<x-badge tone="warning" :dot="false" class="mb-0.5">{{ $warnLabels[is_array($w) ? ($w['code'] ?? '') : $w] ?? (is_array($w) ? ($w['code'] ?? 'Warning') : $w) }}</x-badge> @empty<span class="text-ink-3">—</span>@endforelse
                    </td>
                    <td><button type="button" class="grid size-8 place-items-center rounded-lg text-ink-3 hover:bg-surface-3 hover:text-ink" aria-label="Pay details for {{ $it->employee_name }}" @click="$dispatch('open-payroll-item', @js(['url' => route('admin.payroll.items.show', [$period, $it]), 'title' => $it->employee_name]))"><x-icon name="chevron-right" class="size-4" /></button></td>
                </tr>
            @endforeach
            <x-slot:footer><x-pagination :paginator="$items" /></x-slot:footer>
        </x-data-table>
    </section>
    <x-panel event="open-payroll-item" label="Pay details" />
@endif

{{-- Action dialog (confirm + consequences, optional reason / payment reference) --}}
<x-modal name="payroll-action" size="md" title="Confirm" :initial="$initial" :open="$initial !== []">
    <form id="payroll-action-form" method="POST" :action="data.action" class="space-y-4">
        @csrf
        <input type="hidden" name="_form" value="payroll-action">
        <input type="hidden" name="_key" :value="data.key">
        <p class="text-[13.5px] leading-relaxed text-ink-2" x-text="data.text"></p>
        <div x-show="data.reason" x-cloak>
            <label for="pa-reason" class="mb-1.5 block text-[13px] font-medium text-ink">Reason <span class="text-danger">*</span></label>
            <textarea id="pa-reason" name="reason" rows="3" maxlength="500" class="ctl" x-model="data.reasonValue" x-bind:disabled="!data.reason" @error('reason') aria-invalid="true" @enderror></textarea>
            @error('reason')<p class="mt-1.5 text-xs font-medium text-danger-ink">{{ $message }}</p>@enderror
        </div>
        <div x-show="data.ref" x-cloak>
            <label for="pa-ref" class="mb-1.5 block text-[13px] font-medium text-ink">Payment reference <span class="font-normal text-ink-3">(optional)</span></label>
            <input id="pa-ref" name="payment_reference" maxlength="120" class="ctl" x-model="data.refValue" x-bind:disabled="!data.ref" placeholder="e.g. batch or transfer number">
        </div>
    </form>
    <x-slot:footer>
        <x-button variant="outline" @click="hide()">Keep as is</x-button>
        <button type="submit" form="payroll-action-form" class="btn btn-md" :class="data.danger ? 'btn-danger' : 'btn-primary'" x-text="data.label"></button>
    </x-slot:footer>
</x-modal>

@if ($canManage && ! $locked && ! $cancelled)
    <x-modal name="edit-period" title="Edit period details" description="Dates cannot change. Create a new period for different dates." :open="$editOpen">
        <form id="edit-period-form" method="POST" action="{{ route('admin.payroll.update', $period) }}" class="space-y-4">
            @csrf @method('PUT')
            <input type="hidden" name="_form" value="edit-period">
            <x-input name="name" label="Name" :value="$period->name" maxlength="120" required />
            <x-input name="pay_date" type="date" label="Pay date" :value="$period->pay_date?->toDateString()" />
            <x-textarea name="notes" label="Notes" rows="2" maxlength="500" :value="$period->notes" />
        </form>
        <x-slot:footer><x-button variant="outline" @click="hide()">Cancel</x-button><x-button type="submit" form="edit-period-form">Save</x-button></x-slot:footer>
    </x-modal>
@endif

@if ($canAdjust)
    <x-modal name="adjustment" title="Create adjustment period" description="Corrects a finalized payroll without touching it. You then add entries per employee and take the adjustment through the same approval steps." :open="$adjustOpen">
        <form id="adjustment-form" method="POST" action="{{ route('admin.payroll.adjustments.store', $period) }}" class="space-y-4">
            @csrf
            <input type="hidden" name="_form" value="adjustment">
            <x-textarea name="reason" label="Why is an adjustment needed?" rows="3" maxlength="500" required />
            <x-input name="name" label="Name" placeholder="Defaults to the original name plus Adjustment" maxlength="120" />
            <x-input name="pay_date" type="date" label="Pay date" />
        </form>
        <x-slot:footer><x-button variant="outline" @click="hide()">Cancel</x-button><x-button type="submit" form="adjustment-form">Create adjustment period</x-button></x-slot:footer>
    </x-modal>
@endif

@if ($period->isAdjustment() && $canManage && $desk->canEditEntries($period))
    @php $employeeOptions = $period->original?->run?->items()->orderBy('employee_name')->get(['employee_id', 'employee_name', 'employee_number'])->mapWithKeys(fn ($i) => [$i->employee_id => $i->employee_name.' ('.$i->employee_number.')'])->all() ?? []; @endphp
    <x-modal name="entry" title="Add adjustment entry" description="Only employees paid in the original payroll can be adjusted." :open="$entryOpen">
        <form id="entry-form" method="POST" action="{{ route('admin.payroll.entries.store', $period) }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @csrf
            <input type="hidden" name="_form" value="entry">
            <x-select name="employee_id" label="Employee" :options="$employeeOptions" placeholder="Choose an employee" required class="sm:col-span-2" />
            <x-select name="kind" label="Type" :options="['EARNING' => 'Earning (adds pay)', 'DEDUCTION' => 'Deduction (reduces pay)']" required />
            <x-input name="amount" type="number" step="any" min="0" :label="'Amount ('.($run?->currency ?? $desk->defaultCurrency()).')'" required />
            <x-input name="label" label="Label" placeholder="e.g. Missed overtime for July" maxlength="120" required class="sm:col-span-2" />
            <x-textarea name="reason" label="Reason" rows="2" maxlength="500" required class="sm:col-span-2" />
        </form>
        <x-slot:footer><x-button variant="outline" @click="hide()">Cancel</x-button><x-button type="submit" form="entry-form">Add entry</x-button></x-slot:footer>
    </x-modal>
@endif
@endsection
