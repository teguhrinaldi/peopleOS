{{-- Session flash messages as toasts: success/status/warning fade out, errors stay until dismissed. --}}
@php
    $messages = collect([
        'success' => session('success') ?? session('status'),
        'warning' => session('warning'),
        'danger' => session('error'),
    ])->filter();
@endphp
@if ($messages->isNotEmpty())
    <div class="pointer-events-none fixed right-4 top-4 z-[60] flex w-[min(92vw,380px)] flex-col gap-2 no-print" aria-live="polite">
        @foreach ($messages as $tone => $message)
            <div x-data="toast({{ $tone === 'danger' ? 60000 : 6000 }})" x-show="show" x-transition.opacity.duration.200ms class="pointer-events-auto rounded-xl shadow-pop">
                <x-alert :tone="$tone" class="border border-line bg-surface">
                    <div class="flex items-start gap-3"><span class="flex-1 text-ink">{{ $message }}
                        @if ($items = session($tone === 'danger' ? 'error_items' : 'flash_items'))
                            <ul class="mt-2 max-h-40 list-disc space-y-0.5 overflow-y-auto pl-5 text-[12.5px] text-ink-2">@foreach ($items as $item)<li>{{ $item }}</li>@endforeach</ul>
                        @endif</span>
                        <button type="button" @click="show = false" class="text-ink-3 hover:text-ink" aria-label="Dismiss"><x-icon name="x" class="size-4" /></button></div>
                </x-alert>
            </div>
        @endforeach
    </div>
@endif
