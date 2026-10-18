@php
    use App\Support\DateDisplay;
    $r = $request;
    $ctx = app(\App\Support\CompanyContext::class);
    $num = fn ($v) => $v === null ? '—' : rtrim(rtrim((string) $v, '0'), '.');
    $policyLabels = [
        'annual_allowance' => 'Allowance per year', 'prorate_first_year' => 'Pro-rate first year', 'carry_over_max' => 'Carry-over cap',
        'allow_negative' => 'May go negative', 'max_negative_days' => 'Negative limit', 'allow_half_day' => 'Half days',
        'allow_overlap' => 'Overlap allowed', 'min_notice_days' => 'Notice (days)', 'max_past_days' => 'Back-dating (days)',
        'max_consecutive_days' => 'Max days per request', 'requires_reason' => 'Reason required',
    ];
@endphp
<div class="space-y-6 text-[13.5px]">
    <div class="flex items-start gap-4">
        <x-avatar :name="$r->employee->full_name" :src="$r->employee->photo_path" size="lg" />
        <div class="min-w-0">
            <p class="text-base font-semibold text-ink">{{ $r->employee->full_name }}</p>
            <p class="text-xs text-ink-3">{{ $r->employee->employee_number }}</p>
            <div class="mt-2 flex flex-wrap items-center gap-2"><x-leave-type :type="$r->leaveType" /><x-badge :status="$r->status" /></div>
        </div>
    </div>

    <dl class="grid grid-cols-2 gap-4 rounded-xl bg-surface-2 p-4">
        <div><dt class="text-xs text-ink-3">Dates</dt><dd class="mt-0.5 font-semibold text-ink">{{ DateDisplay::range($r->start_date, $r->end_date) }}@if ($r->half_day) ({{ $r->half_day }})@endif</dd></div>
        <div><dt class="text-xs text-ink-3">Working days</dt><dd class="mt-0.5 text-lg font-bold text-ink">{{ $num($r->days) }}</dd></div>
        <div class="col-span-2"><dt class="text-xs text-ink-3">Reason</dt><dd class="mt-0.5 whitespace-pre-line text-ink">{{ $r->reason ?: '—' }}</dd></div>
        @if ($r->review_note)<div class="col-span-2"><dt class="text-xs text-ink-3">Reviewer note</dt><dd class="mt-0.5 text-ink">{{ $r->review_note }}</dd></div>@endif
        @if ($r->cancel_reason)<div class="col-span-2"><dt class="text-xs text-ink-3">Cancel reason</dt><dd class="mt-0.5 text-ink">{{ $r->cancel_reason }}</dd></div>@endif
    </dl>

    <section>
        <h3 class="mb-2 text-[13px] font-semibold text-ink">Working dates counted</h3>
        @if ($dates)
            <ul class="flex flex-wrap gap-1.5">
                @foreach ($dates as $date => $slots)
                    <li class="rounded-lg bg-surface-2 px-2.5 py-1 text-xs font-medium text-ink ring-1 ring-line">
                        {{ \Carbon\CarbonImmutable::parse($date)->format('D') }} {{ DateDisplay::date(\Carbon\CarbonImmutable::parse($date)) }}@if (count($slots) === 1) <span class="text-ink-3">· {{ $slots[0] }}</span>@endif
                    </li>
                @endforeach
            </ul>
            <p class="mt-2 text-xs text-ink-3">Weekends, rest days and holidays inside the range are not counted.</p>
        @else
            <p class="text-ink-3">No days are held by this request{{ in_array($r->status->value, ['REJECTED', 'CANCELLED'], true) ? ' (released when it was '.strtolower($r->status->value).')' : '' }}.</p>
        @endif
    </section>

    <section>
        <h3 class="mb-2 text-[13px] font-semibold text-ink">Balance · leave year {{ $r->balance?->year ?? '—' }}</h3>
        @if ($unlimited)
            <p class="text-ink-2">{{ $r->leaveType->name }} is not limited by a balance; usage is still tracked.</p>
        @elseif ($impact)
            <dl class="grid grid-cols-4 gap-2 text-center">
                <div class="rounded-lg bg-surface-2 p-2"><dt class="text-[11px] text-ink-3">Entitled</dt><dd class="font-bold text-ink">{{ $num($impact['entitlement']) }}</dd></div>
                <div class="rounded-lg bg-surface-2 p-2"><dt class="text-[11px] text-ink-3">Used</dt><dd class="font-bold text-ink">{{ $num($impact['used']) }}</dd></div>
                <div class="rounded-lg bg-surface-2 p-2"><dt class="text-[11px] text-ink-3">Reserved</dt><dd class="font-bold text-ink">{{ $num($impact['reserved']) }}</dd></div>
                <div class="rounded-lg bg-primary-soft p-2"><dt class="text-[11px] text-primary-ink">Available</dt><dd class="font-bold text-primary-ink">{{ $num($impact['available']) }}</dd></div>
            </dl>
            @if ($r->status->value === 'PENDING')
                <p class="mt-2 text-xs text-ink-3">These {{ $num($r->days) }} day(s) are already reserved: approving keeps {{ $num($impact['after']) }} available, rejecting gives them back.</p>
            @endif
        @else
            <p class="text-ink-3">No balance is linked to this request.</p>
        @endif
    </section>

    @if ($policy)
        <details class="group rounded-xl border border-line">
            <summary class="flex cursor-pointer list-none items-center justify-between px-4 py-3 text-[13px] font-semibold text-ink">
                Policy at submission <x-icon name="chevron-down" class="size-4 transition group-open:rotate-180" />
            </summary>
            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 border-t border-line px-4 py-3 text-xs">
                @foreach ($policyLabels as $key => $label)
                    @if (array_key_exists($key, $policy))
                        @php $v = $policy[$key]; @endphp
                        <dt class="text-ink-3">{{ $label }}</dt>
                        <dd class="font-medium text-ink">{{ is_bool($v) ? ($v ? 'Yes' : 'No') : ($v === null ? ($key === 'annual_allowance' ? 'Unlimited' : 'No limit') : $num($v)) }}</dd>
                    @endif
                @endforeach
            </dl>
            <p class="border-t border-line px-4 py-2 text-[11px] text-ink-3">Frozen when the request was submitted; later policy edits never reinterpret it.</p>
        </details>
    @endif

    <section>
        <h3 class="mb-3 text-[13px] font-semibold text-ink">History</h3>
        <x-timeline :items="$history->map(fn ($h) => [
            'title' => ($h->actor?->name ?? 'System').' · '.str_replace(['leave.', '_'], ['', ' '], $h->action),
            'meta' => $ctx->toLocal($h->created_at)->format('d M Y, H:i'),
            'body' => $h->new_values['review_note'] ?? $h->new_values['cancel_reason'] ?? null,
            'tone' => match ($h->action) { 'leave.approved' => 'success', 'leave.rejected' => 'danger', 'leave.cancelled' => 'muted', default => 'primary' },
            'icon' => match ($h->action) { 'leave.approved' => 'circle-check', 'leave.rejected' => 'circle-alert', 'leave.cancelled' => 'x', default => 'clipboard-list' },
        ])->all()" />
        @if ($requestedBy && $requestedBy !== $r->employee->full_name)
            <p class="mt-3 text-xs text-ink-3">Filed by {{ $requestedBy }} on the employee's behalf.</p>
        @endif
    </section>

    @if ($canDecide || $canCancel)
        <div class="flex flex-wrap justify-end gap-2 border-t border-line pt-4">
            @if ($canCancel)
                <x-button variant="outline" :dispatch="['name' => 'leave-cancel', 'data' => ['action' => route('admin.leave-requests.cancel', $r), 'reason' => '']]">Cancel leave</x-button>
            @endif
            @if ($canDecide)
                <x-button variant="danger-soft" :dispatch="['name' => 'leave-decision', 'data' => ['action' => route('admin.leave-requests.decide', $r), 'decision' => 'reject', 'note' => '', 'title' => 'Reject leave for '.$r->employee->full_name]]">Reject</x-button>
                <x-button :dispatch="['name' => 'leave-decision', 'data' => ['action' => route('admin.leave-requests.decide', $r), 'decision' => 'approve', 'note' => '', 'title' => 'Approve leave for '.$r->employee->full_name]]">Approve</x-button>
            @endif
        </div>
    @endif
</div>
