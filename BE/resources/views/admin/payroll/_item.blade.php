@php
    use App\Enums\SalaryComponentKind as K;
    use App\Services\Admin\PayrollDesk;
    use App\Support\DateDisplay;
    $money = fn ($v, $cur = false) => PayrollDesk::money($v, $run, $cur);
    $earnings = $item->lines->filter(fn ($l) => $l->kind === K::Earning)->sortBy('sort_order');
    $deductions = $item->lines->filter(fn ($l) => $l->kind === K::Deduction)->sortBy('sort_order');
    $warnLabels = ['NEGATIVE_NET' => 'Net pay is negative', 'NO_BASIC_SALARY' => 'No basic salary was set for this employee', 'NO_WORKING_DAYS' => 'There were no working days in the period'];
    $num = fn ($v) => $v === null ? null : rtrim(rtrim((string) $v, '0'), '.');
@endphp
<div class="space-y-6">
    <div>
        <p class="text-lg font-bold text-ink">{{ $item->employee_name }}</p>
        <p class="text-[13px] text-ink-3">{{ $item->employee_number }}@if ($item->position_name) &middot; {{ $item->position_name }}@endif @if ($item->department_name) &middot; {{ $item->department_name }}@endif</p>
        <p class="mt-1 text-xs text-ink-3">Figures are the snapshot taken when this payroll was generated. They do not change if the employee record changes later.</p>
    </div>

    @foreach ((array) $item->warnings as $w)
        @php $code = is_array($w) ? ($w['code'] ?? '') : $w; @endphp
        <x-alert :tone="$code === 'NEGATIVE_NET' ? 'danger' : 'warning'">{{ $warnLabels[$code] ?? $code }}</x-alert>
    @endforeach

    <section aria-labelledby="inputs-h">
        <h3 id="inputs-h" class="mb-2 text-[13px] font-semibold uppercase tracking-wide text-ink-3">Inputs</h3>
        <x-kv :items="[
            'Salary as of' => DateDisplay::date($item->salary_reference_date),
            'Basic salary' => $money($item->basic_salary, true),
            'Working days' => $item->working_days,
            'Present days' => $item->present_days,
            'Overtime' => floor($item->overtime_minutes / 60).' h '.($item->overtime_minutes % 60).' min',
            'Unpaid leave days' => $num($item->unpaid_leave_days) ?: '0',
            'Employment' => ucfirst(str_replace('_', ' ', (string) $item->employment_type)).($item->branch_name ? ' · '.$item->branch_name : ''),
        ]" />
    </section>

    @foreach (['Earnings' => $earnings, 'Deductions' => $deductions] as $heading => $lines)
        <section aria-label="{{ $heading }}">
            <h3 class="mb-2 text-[13px] font-semibold uppercase tracking-wide text-ink-3">{{ $heading }}</h3>
            @if ($lines->isEmpty())
                <p class="rounded-lg bg-surface-2 px-3 py-2.5 text-[13px] text-ink-3">No {{ strtolower($heading) }}.</p>
            @else
                <ul class="divide-y divide-line rounded-xl border border-line">
                    @foreach ($lines as $l)
                        <li class="flex items-start justify-between gap-4 px-3.5 py-2.5">
                            <span class="min-w-0">
                                <span class="block text-[13.5px] font-medium text-ink">{{ $l->component_name }}</span>
                                <span class="block text-xs text-ink-3">{{ $l->component_code }}@if ($l->description) &middot; {{ $l->description }}@endif</span>
                                @if ($l->adjustment_reason)<span class="block text-xs text-info-ink">Adjustment: {{ $l->adjustment_reason }}</span>@endif
                            </span>
                            <span class="shrink-0 text-[13.5px] font-semibold tabular-nums text-ink">{{ $money($l->amount) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endforeach

    <dl class="space-y-2 rounded-xl bg-surface-2 p-4 text-[13.5px]">
        <div class="flex justify-between"><dt class="text-ink-2">Gross pay</dt><dd class="font-semibold tabular-nums text-ink">{{ $money($item->gross_pay, true) }}</dd></div>
        <div class="flex justify-between"><dt class="text-ink-2">Total deductions</dt><dd class="font-semibold tabular-nums text-ink">{{ $money($item->total_deductions, true) }}</dd></div>
        <div class="flex justify-between border-t border-line pt-2 text-base"><dt class="font-semibold text-ink">Net pay</dt><dd class="font-bold tabular-nums {{ $item->has_negative_net ? 'text-danger-ink' : 'text-ink' }}">{{ $money($item->net_pay, true) }}</dd></div>
    </dl>

    @if ($item->payslip && ($canPayslip ?? false))
        <a href="{{ route('admin.payslips.show', $item->payslip) }}" class="btn btn-md btn-outline w-full"><x-icon name="receipt" class="size-4" />Open payslip {{ $item->payslip->payslip_number }}</a>
    @endif
</div>
