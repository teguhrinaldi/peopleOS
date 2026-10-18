{{--
    Leave policy fields. $prefix: input name prefix ("policy" or "ep"); $p: LeavePolicy (values); $live: true only for the
    form that was just submitted, so old input and errors never leak into the other policy forms on the page; $uid: id prefix.
--}}
@php
    $dot = fn ($k) => "{$prefix}.{$k}";
    $val = function ($k, $default) use ($live, $dot) {
        return $live ? old($dot($k), $default) : $default;
    };
    $err = fn ($k) => $live ? $errors->first($dot($k)) : null;
    $num = fn ($v) => $v === null || $v === '' ? '' : rtrim(rtrim((string) $v, '0'), '.');
    $numbers = [
        'annual_allowance' => ['Allowance (days per leave year)', 'Leave empty for unlimited (usage is still tracked). Half days allowed, e.g. 12.5.', $num($p->annual_allowance), '0.5'],
        'carry_over_max' => ['Carry-over cap (days)', 'Unused days moved into the next year, up to this many. 0 = none.', $num($p->carry_over_max ?? 0), '0.5'],
        'min_notice_days' => ['Notice required (days)', 'Earliest allowed start is today + this many days. 0 = same day.', $p->min_notice_days ?? 0, '1'],
        'max_past_days' => ['Back-dating allowed (days)', 'How far in the past a request may start, e.g. 7 for sick leave. 0 = never.', $p->max_past_days ?? 0, '1'],
        'max_consecutive_days' => ['Max working days per request', 'Leave empty for no limit.', $p->max_consecutive_days, '1'],
        'max_negative_days' => ['Negative limit (days)', 'Only used when borrowing is allowed.', $num($p->max_negative_days ?? 0), '0.5'],
    ];
    $flags = [
        'prorate_first_year' => ['Pro-rate the first year', 'New joiners get the allowance for the remaining whole months.', $p->prorate_first_year ?? true],
        'allow_half_day' => ['Allow half days', 'Single-day requests can be morning (AM) or afternoon (PM).', $p->allow_half_day ?? true],
        'requires_reason' => ['Reason required', 'Employees must explain the request when submitting.', $p->requires_reason ?? true],
        'allow_negative' => ['Allow borrowing', 'Let the balance go below zero, up to the negative limit.', (bool) $p->allow_negative],
        'allow_overlap' => ['Allow overlapping requests', 'Normally a day can only be covered by one request.', (bool) $p->allow_overlap],
    ];
@endphp
<div class="grid grid-cols-1 gap-4 sm:col-span-2 sm:grid-cols-2">
    @foreach ($numbers as $key => [$label, $help, $value, $step])
        @php $id = "{$uid}-{$key}"; $e = $err($key); @endphp
        <div>
            <label for="{{ $id }}" class="mb-1.5 block text-[13px] font-medium text-ink">{{ $label }}</label>
            <input id="{{ $id }}" type="number" min="0" step="{{ $step }}" name="{{ $prefix }}[{{ $key }}]" value="{{ $val($key, $value) }}" class="ctl" @if ($e) aria-invalid="true" @endif aria-describedby="{{ $id }}-h">
            <p id="{{ $id }}-h" class="mt-1.5 text-xs {{ $e ? 'font-medium text-danger-ink' : 'text-ink-3' }}">{{ $e ?: $help }}</p>
        </div>
    @endforeach
    @foreach ($flags as $key => [$label, $help, $value])
        @php $id = "{$uid}-{$key}"; $on = (bool) filter_var($val($key, $value), FILTER_VALIDATE_BOOLEAN); @endphp
        <label for="{{ $id }}" class="flex cursor-pointer items-start gap-3 rounded-xl border border-line p-3 hover:bg-surface-2">
            <input type="hidden" name="{{ $prefix }}[{{ $key }}]" value="0">
            <input id="{{ $id }}" type="checkbox" name="{{ $prefix }}[{{ $key }}]" value="1" @checked($on) class="mt-0.5 size-4 shrink-0 rounded accent-[var(--primary)]">
            <span><span class="block text-[13px] font-medium text-ink">{{ $label }}</span><span class="block text-xs text-ink-3">{{ $help }}</span></span>
        </label>
    @endforeach
</div>
