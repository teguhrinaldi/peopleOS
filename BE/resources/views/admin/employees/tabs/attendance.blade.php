@php $sum = $attendance['summary']; @endphp
<div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
    <x-stat-card label="Days recorded" :value="$sum['days']" icon="calendar-days" tone="primary" hint="Last 30 records" />
    <x-stat-card label="Late arrivals" :value="$sum['late']" icon="clock" tone="warning" />
    <x-stat-card label="Hours worked" :value="$sum['hours']" icon="timer" tone="success" />
    <x-stat-card label="Flagged for review" :value="$sum['flagged']" icon="flag" tone="danger" />
</div>

<x-data-table :empty="$attendance['records']->isEmpty()" emptyIcon="clock" emptyTitle="No attendance yet" emptyText="Records appear here after the employee's first check-in.">
    <x-slot:head>
        <th>Date</th><th>Shift</th><th>Check in</th><th>Check out</th><th>Worked</th><th>Status</th>
    </x-slot:head>
    @foreach ($attendance['records'] as $row)
        <tr>
            <td class="whitespace-nowrap font-medium"><a href="{{ route('admin.attendance.show', $row) }}" class="text-ink hover:text-primary-ink">{{ $row->work_date->format('D, d M Y') }}</a></td>
            <td>{{ $row->schedule?->shift_name ?? '—' }}</td>
            <td class="tabular-nums">{{ \App\Support\Fmt::time($row->check_in_at) ?? '—' }}</td>
            <td class="tabular-nums">{{ \App\Support\Fmt::time($row->check_out_at) ?? '—' }}</td>
            <td class="tabular-nums">{{ $row->worked_minutes ? intdiv($row->worked_minutes, 60).'h '.($row->worked_minutes % 60).'m' : '—' }}</td>
            <td>
                @if ($row->needs_review)<x-badge status="pending">Needs review</x-badge>
                @elseif ($row->status === \App\Enums\AttendanceStatus::Rejected)<x-badge status="rejected" />
                @elseif ($row->is_late)<x-badge status="late">Late · {{ $row->late_minutes }}m</x-badge>
                @elseif ($row->check_in_at)<x-badge status="on_time">On time</x-badge>
                @else<x-badge status="absent" />@endif
            </td>
        </tr>
    @endforeach
</x-data-table>
<div class="mt-3 flex flex-wrap items-center justify-between gap-3">
    <p class="text-xs text-ink-3">Read-only view of the latest 30 records. Corrections are handled in the Attendance module.</p>
    <x-button variant="outline" size="sm" icon="clock" :href="route('admin.attendance', ['employee_id' => $employee->id, 'from' => app(\App\Support\CompanyContext::class)->today()->subDays(30)->toDateString(), 'to' => app(\App\Support\CompanyContext::class)->today()->toDateString()])">Open in Attendance</x-button>
</div>
