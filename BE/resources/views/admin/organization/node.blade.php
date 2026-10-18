{{-- One department in the structure tree; recurses into sub-departments. --}}
<li class="py-1">
    <div class="flex items-center justify-between gap-3 text-[13px]">
        <span class="flex min-w-0 items-center gap-2 text-ink-2"><span class="size-1.5 shrink-0 rounded-full {{ $node['active'] ? 'bg-primary' : 'bg-ink-3/50' }}"></span><span class="truncate {{ $node['active'] ? '' : 'line-through opacity-70' }}">{{ $node['name'] }}</span></span>
        <span class="shrink-0 tabular-nums text-xs font-semibold text-ink-2">{{ $node['headcount'] }}</span>
    </div>
    @if ($node['children'])
        <ul class="ml-3 mt-0.5 border-l border-line pl-3">
            @foreach ($node['children'] as $child) @include('admin.organization.node', ['node' => $child]) @endforeach
        </ul>
    @endif
</li>
