@php
    use App\Services\Admin\PayrollDesk;
    use App\Support\DateDisplay;
    $user = auth()->user();
    $salary = $payroll['salary'];
    $payslips = $payroll['payslips'];
    $num = fn ($v) => $v === null ? null : rtrim(rtrim((string) $v, '0'), '.');
    $canChange = $salary !== null && $user->hasPermission('salary.manage') && ! $employee->isTerminated();
    $changeOpen = old('_form') === 'salary-change';
    $today = app(\App\Support\CompanyContext::class)->today()->toDateString();
    $held = $salary ? $salary['current']->keyBy('salary_component_id') : collect();
    $display = fn ($r) => $r->amount !== null ? PayrollDesk::plain((string) $r->amount) : ($r->rate !== null ? $num($r->rate).' %' : ($r->component->calculation->value === 'UNPAID_LEAVE_DEDUCTION' ? '100 % of daily rate' : '—'));
@endphp

@if ($salary !== null)
    <x-alert tone="warning" icon="info" title="No tax or statutory rules are built in" class="mb-5">Pay is calculated from the components you configure. Set up tax and statutory deductions for your jurisdiction as components.</x-alert>

    <x-card title="Current salary" :subtitle="'Values in force on '.DateDisplay::date(\Carbon\CarbonImmutable::parse($salary['today']))" class="mb-6" :padded="false">
        <x-slot:actions>
            @if ($canChange)<x-button size="sm" icon="pencil" :dispatch="['name' => 'salary-change']">Change salary from date</x-button>@endif
        </x-slot:actions>
        @if ($salary['current']->isEmpty())
            <x-empty-state icon="wallet" title="No salary set up" text="This employee is skipped by payroll until a basic salary is set. Assign a salary structure or change the salary from a date." class="py-8" />
        @else
            <div class="relative overflow-x-auto"><table class="dt">
                <thead><tr><th>Component</th><th>Kind</th><th class="text-right">Value</th><th class="hidden sm:table-cell">Since</th></tr></thead>
                <tbody>
                @foreach ($salary['current'] as $r)
                    <tr>
                        <td><span class="font-medium text-ink">{{ $r->component->name }}</span>@if ($r->component->is_basic) <x-badge tone="info" :dot="false">Basic</x-badge>@endif<span class="block text-xs text-ink-3">{{ \App\Services\Admin\SalaryDesk::CALCULATIONS[$r->component->calculation->value] ?? '' }}</span></td>
                        <td><x-badge :tone="$r->component->kind->value === 'EARNING' ? 'success' : 'warning'" :dot="false">{{ ucfirst(strtolower($r->component->kind->value)) }}</x-badge></td>
                        <td class="text-right font-semibold tabular-nums">{{ $display($r) }}</td>
                        <td class="hidden whitespace-nowrap sm:table-cell">{{ DateDisplay::date($r->effective_from) }}</td>
                    </tr>
                @endforeach
                </tbody></table></div>
        @endif
    </x-card>

    <x-card title="Salary history" subtitle="Append-only: a change closes the previous value the day before and starts a new one. Nothing is overwritten or deleted." class="mb-6">
        @if ($salary['groups']->isEmpty())
            <p class="py-4 text-center text-[13px] text-ink-3">No salary has been recorded yet.</p>
        @else
            <ol class="space-y-5">
                @foreach ($salary['groups'] as $date => $rows)
                    <li>
                        <div class="mb-2 flex flex-wrap items-center gap-2"><span class="text-[13px] font-semibold text-ink">From {{ DateDisplay::date(\Carbon\CarbonImmutable::parse($date)) }}</span>
                            @php $first = $rows->first(); @endphp
                            @if ($first->reason)<span class="text-xs text-ink-3">&middot; {{ $first->reason }}</span>@endif
                            @if ($first->structure)<x-badge tone="neutral" :dot="false">Structure {{ $first->structure->name }}</x-badge>@endif
                            <span class="text-xs text-ink-3">&middot; by {{ $first->creator?->name ?? 'system' }}</span></div>
                        <ul class="divide-y divide-line rounded-xl border border-line">
                            @foreach ($rows as $r)
                                <li class="flex items-center justify-between gap-3 px-3.5 py-2 text-[13px]">
                                    <span class="min-w-0 truncate text-ink">{{ $r->component->name }}</span>
                                    <span class="flex shrink-0 items-center gap-3"><span class="font-semibold tabular-nums text-ink">{{ $display($r) }}</span>
                                        @if ($r->effective_to)<span class="text-xs text-ink-3">until {{ DateDisplay::date($r->effective_to) }}</span>@else<x-badge status="active" :dot="false">Current</x-badge>@endif</span>
                                </li>
                            @endforeach
                        </ul>
                    </li>
                @endforeach
            </ol>
        @endif
    </x-card>
@endif

@if ($payslips !== null)
    <x-data-table :empty="$payslips->isEmpty()" emptyIcon="receipt" emptyTitle="No payslips yet" emptyText="Payslips appear here once a payroll period that includes this employee is finalized.">
        <x-slot:head><th>Payslip</th><th class="hidden sm:table-cell">Period</th><th class="text-right">Net pay</th><th class="w-20"><span class="sr-only">Open</span></th></x-slot:head>
        @foreach ($payslips as $s)
            <tr>
                <td><a href="{{ route('admin.payslips.show', $s) }}" class="font-semibold text-ink hover:text-primary-ink">{{ $s->payslip_number }}</a><span class="block text-xs text-ink-3">{{ DateDisplay::moment($s->issued_at) }}</span></td>
                <td class="hidden sm:table-cell">{{ $s->period->name }}</td>
                <td class="text-right font-semibold tabular-nums">{{ PayrollDesk::money($s->item->net_pay, $s->item->run) }}</td>
                <td class="text-right"><a href="{{ route('admin.payslips.pdf', $s) }}" target="_blank" rel="noopener" class="inline-grid size-8 place-items-center rounded-lg text-ink-3 hover:bg-surface-3 hover:text-ink" aria-label="Open PDF {{ $s->payslip_number }}"><x-icon name="file-text" class="size-4" /></a></td>
            </tr>
        @endforeach
    </x-data-table>
@endif

@if ($canChange)
    <x-modal name="salary-change" size="lg" title="Change salary from date" description="Adds a new dated value for each component you change. Earlier values stay in the history." :open="$changeOpen">
        <form id="salary-change-form" method="POST" action="{{ route('admin.employees.salary.store', $employee) }}" class="space-y-4">
            @csrf
            <input type="hidden" name="_form" value="salary-change">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-input name="effective_from" type="date" label="Effective from" :value="$today" required />
                <x-input name="reason" label="Reason" placeholder="e.g. Annual raise" maxlength="255" />
            </div>
            <p class="text-xs text-ink-3">Leave a value empty to keep it as is (or not hold that component). Tick Remove to end a component from the date.</p>
            <ul class="max-h-72 divide-y divide-line overflow-y-auto rounded-xl border border-line">
                @foreach ($payroll['choices'] as $c)
                    @php $cur = $held->get($c['id']); @endphp
                    <li class="grid grid-cols-[1fr_130px] items-center gap-3 px-3 py-2 sm:grid-cols-[1fr_150px_auto]">
                        <label for="sv-{{ $c['id'] }}" class="min-w-0 text-[13px]"><span class="block truncate font-medium text-ink">{{ $c['label'] }}</span><span class="text-xs text-ink-3">{{ $c['unit'] === 'rate' ? 'percentage' : 'amount' }}@if ($cur) &middot; now {{ $display($cur) }}@endif</span></label>
                        <input id="sv-{{ $c['id'] }}" type="number" step="any" min="0" name="values[{{ $c['id'] }}]" value="{{ old('values.'.$c['id']) }}" class="ctl" placeholder="{{ $cur ? 'Unchanged' : 'Not held' }}" @disabled($c['unit'] === 'none')>
                        @if ($cur)<label class="col-span-2 flex items-center gap-2 text-xs text-ink-2 sm:col-span-1"><input type="checkbox" name="remove[]" value="{{ $c['id'] }}" class="size-4 accent-[var(--primary)]" @checked(in_array($c['id'], (array) old('remove', [])))>Remove</label>@else<span class="hidden sm:block"></span>@endif
                    </li>
                @endforeach
            </ul>
            @error('values.*')<p class="text-xs font-medium text-danger-ink">{{ $message }}</p>@enderror
        </form>
        <x-slot:footer><x-button variant="outline" @click="hide()">Cancel</x-button><x-button type="submit" form="salary-change-form">Save salary change</x-button></x-slot:footer>
    </x-modal>
@endif
