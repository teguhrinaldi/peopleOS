@php
    $items = $activity->map(fn ($a) => [
        'title' => $a['actor'].' '.$a['verb'].($a['subject'] && ! $a['subject_url'] ? ' '.$a['subject'] : ''),
        'meta' => $a['at']->format('d M Y, H:i'),
        'body' => implode(' · ', $a['changes']),
        'tone' => $a['tone'],
        'icon' => $a['icon'],
    ])->all();
@endphp
<x-card title="Activity" subtitle="Audit trail for this employee, newest first.">
    @if ($items)
        <x-timeline :items="$items" />
    @else
        <p class="py-6 text-center text-[13px] text-ink-3">No activity recorded for this employee.</p>
    @endif
</x-card>
