@php
    $items = $history->map(function ($a) {
        $open = $a->effective_to === null;
        $parts = array_filter([
            $a->manager ? 'Manager: '.$a->manager->full_name : null,
            $a->workLocation ? 'Location: '.$a->workLocation->name : null,
            $a->shift ? 'Shift: '.$a->shift->name : null,
            $a->branch ? 'Branch: '.$a->branch->name : null,
        ]);

        return [
            'title' => ($a->department?->name ?? '—').' · '.($a->position?->name ?? '—'),
            'meta' => $a->effective_from->format('d M Y').' → '.($open ? 'present' : $a->effective_to->format('d M Y')),
            'body' => trim(implode(' · ', $parts).($a->reason ? ($parts ? ' — ' : '').$a->reason : '')),
            'tone' => $open ? 'primary' : 'muted',
            'icon' => $open ? 'git-branch' : 'history',
        ];
    })->all();
@endphp
<div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    <x-card title="Current placement" class="lg:col-span-1">
        <x-kv :items="[
            'Department' => $placement?->department?->name,
            'Position' => $placement?->position?->name,
            'Manager' => $placement?->manager?->full_name,
            'Branch' => $placement?->branch?->name,
            'Work location' => $placement?->workLocation?->name,
            'Shift' => $placement?->shift?->name,
            'Since' => $placement?->effective_from?->format('d M Y'),
        ]" />
        @if ($canEdit)
            <x-button variant="outline" class="mt-5 w-full" icon="git-branch" @click="$dispatch('open-modal', { name: 'reassign' })">Change assignment</x-button>
        @endif
    </x-card>

    <x-card title="Assignment history" subtitle="Every change keeps its own dates; nothing is overwritten." class="lg:col-span-2">
        @if ($items)
            <x-timeline :items="$items" />
        @else
            <p class="text-[13px] text-ink-3">No assignments recorded.</p>
        @endif
    </x-card>
</div>
