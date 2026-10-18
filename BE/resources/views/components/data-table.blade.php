@props(['empty' => false, 'emptyTitle' => 'Nothing here yet', 'emptyText' => null, 'emptyIcon' => 'search'])
<div {{ $attributes->class(['card overflow-hidden dt-sticky']) }}>
    @if ($empty)
        <x-empty-state :icon="$emptyIcon" :title="$emptyTitle" :text="$emptyText">
            {{ $emptyAction ?? '' }}
        </x-empty-state>
    @else
        <div class="relative overflow-x-auto">
            <table class="dt">
                <thead><tr>{{ $head }}</tr></thead>
                <tbody>{{ $slot }}</tbody>
            </table>
        </div>
    @endif
    @isset($footer)
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-line px-4 py-3">{{ $footer }}</div>
    @endisset
</div>
