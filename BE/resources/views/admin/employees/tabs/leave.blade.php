@php
    use App\Support\DateDisplay;
    $num = fn ($v) => $v === null ? '—' : rtrim(rtrim((string) $v, '0'), '.');
    $canManage = auth()->user()->can('leave.manage') && ! $employee->isTerminated();
    $types = collect($leave['balances'])->pluck('type')->mapWithKeys(fn ($t) => [$t->id => $t->name])->all();
    $fileOpen = old('_form') === 'file-leave';
    $adjustOpen = old('_form') === 'adjust-balance';
@endphp

<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <nav class="flex gap-2" aria-label="Leave year">
        @foreach ($leave['years'] as $y)
            <a href="{{ route('admin.employees.show', [$employee, 'tab' => 'leave', 'year' => $y]) }}" @class(['rounded-full px-3.5 py-1.5 text-[13px] font-semibold tabular-nums transition', 'bg-primary text-white' => $y === $leave['year'], 'bg-surface text-ink-2 ring-1 ring-line hover:text-ink' => $y !== $leave['year']]) @if ($y === $leave['year']) aria-current="page" @endif>{{ $y }}</a>
        @endforeach
    </nav>
    @if ($canManage && $employee->isActive())
        <x-button icon="plus" :dispatch="['name' => 'file-leave']">File leave for {{ \Illuminate\Support\Str::before($employee->full_name, ' ') }}</x-button>
    @endif
</div>

<section aria-label="Balances" class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
    @forelse ($leave['balances'] as ['type' => $type, 'balance' => $b, 'error' => $error])
        <x-card>
            <div class="flex items-start justify-between gap-2">
                <x-leave-type :type="$type" />
                @if ($b && $canManage && ! $b->isUnlimited())
                    <x-button variant="text" size="sm" icon="pencil" :dispatch="['name' => 'adjust-balance', 'data' => ['action' => route('admin.employees.leave.adjust', [$employee, $b]), 'title' => 'Adjust '.$type->name.' · '.$b->year, 'delta' => '', 'reason' => '']]">Adjust</x-button>
                @endif
            </div>
            @if ($error)
                <p class="mt-3 text-[13px] text-ink-3">{{ $error }}</p>
            @elseif ($b->isUnlimited())
                <p class="mt-3 text-2xl font-bold text-ink">Unlimited</p>
                <p class="text-xs text-ink-3">{{ $num($b->used) }} used · {{ $num($b->reserved) }} pending</p>
            @else
                @php $ent = (float) $b->entitlement(); $pct = $ent > 0 ? min(100, ((float) $b->used + (float) $b->reserved) / $ent * 100) : 0; @endphp
                <p class="mt-3"><span class="text-[28px] font-bold leading-none text-ink">{{ $num($b->available()) }}</span> <span class="text-[13px] text-ink-3">of {{ $num($b->entitlement()) }} days available</span></p>
                <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-surface-3" role="img" aria-label="{{ $num($b->used) }} used and {{ $num($b->reserved) }} pending of {{ $num($b->entitlement()) }}">
                    <span class="block h-full rounded-full {{ \App\Support\LeaveColors::solid($type->color) }}" style="width: {{ $pct }}%"></span>
                </div>
                <dl class="mt-3 grid grid-cols-4 gap-1 text-center text-[11px]">
                    <div><dt class="text-ink-3">Allowance</dt><dd class="font-semibold text-ink">{{ $num($b->allowance) }}</dd></div>
                    <div><dt class="text-ink-3">Carried</dt><dd class="font-semibold text-ink">{{ $num($b->carried_over) }}</dd></div>
                    <div><dt class="text-ink-3">Adjusted</dt><dd class="font-semibold text-ink">{{ (float) $b->adjustment > 0 ? '+' : '' }}{{ $num($b->adjustment) }}</dd></div>
                    <div><dt class="text-ink-3">Used / pending</dt><dd class="font-semibold text-ink">{{ $num($b->used) }} / {{ $num($b->reserved) }}</dd></div>
                </dl>
            @endif
        </x-card>
    @empty
        <x-card class="sm:col-span-2 xl:col-span-3"><p class="text-[13px] text-ink-3">No active leave types are configured.</p></x-card>
    @endforelse
</section>

<x-data-table :empty="$leave['requests']->isEmpty()" emptyIcon="calendar-days" :emptyTitle="'No leave in '.$leave['year']" emptyText="Requests from the app, or filed by HR, appear here.">
    <x-slot:head><th>Type</th><th>Dates</th><th class="text-right">Days</th><th class="hidden md:table-cell">Reason</th><th>Status</th><th class="w-10"><span class="sr-only">Open</span></th></x-slot:head>
    @foreach ($leave['requests'] as $r)
        <tr>
            <td><x-leave-type :type="$r->leaveType" /></td>
            <td class="whitespace-nowrap">{{ DateDisplay::range($r->start_date, $r->end_date) }}@if ($r->half_day) <span class="text-xs text-ink-3">({{ $r->half_day }})</span>@endif</td>
            <td class="text-right font-semibold tabular-nums text-ink">{{ $num($r->days) }}</td>
            <td class="hidden max-w-72 md:table-cell"><span class="line-clamp-1 text-xs">{{ $r->reason ?? '—' }}</span></td>
            <td><x-badge :status="$r->status" />@if ($r->review_note)<span class="mt-1 block max-w-56 truncate text-xs text-ink-3" title="{{ $r->review_note }}">“{{ $r->review_note }}”</span>@endif</td>
            <td class="text-right"><a href="{{ route('admin.leave-requests.show', $r) }}" class="grid size-8 place-items-center rounded-lg text-ink-3 hover:bg-surface-3 hover:text-ink" aria-label="Open request"><x-icon name="chevron-right" class="size-4" /></a></td>
        </tr>
    @endforeach
</x-data-table>

@if ($canManage)
    <x-modal name="file-leave" title="File leave on behalf" :open="$fileOpen" size="lg"
        :initial="['leave_type_id' => (string) old('leave_type_id', ''), 'start_date' => old('start_date', ''), 'end_date' => old('end_date', ''), 'half_day' => old('half_day', ''), 'reason' => old('reason', '')]">
        <form id="file-leave-form" method="POST" action="{{ route('admin.employees.leave.store', $employee) }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @csrf
            <input type="hidden" name="_form" value="file-leave">
            <p class="text-[13px] text-ink-2 sm:col-span-2">The request is submitted for {{ $employee->full_name }} with the same rules as the app (balance, notice, overlap) and then waits for review.</p>
            <x-mfield name="leave_type_id" as="select" label="Leave type" :options="$types" placeholder="Select type" required class="sm:col-span-2" />
            <x-mfield name="start_date" type="date" label="From" required />
            <x-mfield name="end_date" type="date" label="To" required />
            <x-mfield name="half_day" as="select" label="Half day" :options="['AM' => 'Morning (AM)', 'PM' => 'Afternoon (PM)']" placeholder="Full day(s)" helper="Single-day requests only." />
            <x-mfield name="reason" as="textarea" label="Reason" :rows="2" class="sm:col-span-2" />
        </form>
        <x-slot:footer>
            <x-button variant="outline" @click="hide()">Cancel</x-button>
            <x-button type="submit" form="file-leave-form">Submit request</x-button>
        </x-slot:footer>
    </x-modal>

    <x-modal name="adjust-balance" title="Adjust balance" :open="$adjustOpen" size="sm"
        :initial="['action' => old('_action'), 'title' => 'Adjust balance', 'delta' => old('delta', ''), 'reason' => old('reason', '')]">
        <form id="adjust-balance-form" method="POST" :action="data.action" class="space-y-4">
            @csrf
            <input type="hidden" name="_form" value="adjust-balance">
            <input type="hidden" name="_action" :value="data.action">
            <x-mfield name="delta" label="Days to add or remove" placeholder="e.g. 2 or -1.5" helper="Half-day steps. Negative removes days; available can never drop below zero." required inputmode="decimal" />
            <x-mfield name="reason" as="textarea" label="Reason" :rows="2" helper="Required; recorded in the audit log." required />
        </form>
        <x-slot:footer>
            <x-button variant="outline" @click="hide()">Cancel</x-button>
            <x-button type="submit" form="adjust-balance-form">Save adjustment</x-button>
        </x-slot:footer>
    </x-modal>
@endif
