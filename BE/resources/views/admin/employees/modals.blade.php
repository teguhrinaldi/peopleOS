@php
    $reassignOpen = old('_form') === 'reassign';
    $terminateOpen = old('_form') === 'terminate';
    $current = $placement;
    $pick = fn ($key, $fallback) => old($key, $fallback);
@endphp

{{-- Change assignment: closes the open assignment the day before and starts a new dated one. --}}
<x-modal name="reassign" title="Change assignment" description="History is kept. The current placement ends the day before the new one starts." size="lg" :open="$reassignOpen">
    <form id="reassign-form" method="POST" action="{{ route('admin.employees.reassign', $employee) }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        @csrf
        <input type="hidden" name="_form" value="reassign">
        <x-select name="department_id" label="Department" :options="$options['departments'] ?? []" :value="$pick('department_id', $current?->department_id)" required />
        <x-select name="position_id" label="Position" :options="$options['positions'] ?? []" :value="$pick('position_id', $current?->position_id)" required />
        <x-select name="manager_id" label="Manager" :options="$options['managers'] ?? []" :value="$pick('manager_id', $current?->manager_id)" placeholder="No manager" />
        <x-select name="branch_id" label="Branch" :options="$options['branches'] ?? []" :value="$pick('branch_id', $current?->branch_id)" placeholder="Use the department's branch" />
        <x-select name="work_location_id" label="Work location" :options="$options['locations'] ?? []" :value="$pick('work_location_id', $current?->work_location_id)" placeholder="Not assigned" />
        <x-select name="shift_id" label="Shift" :options="$options['shifts'] ?? []" :value="$pick('shift_id', $current?->shift_id)" placeholder="Not assigned" />
        <x-input name="effective_from" type="date" label="Effective from" :value="app(\App\Support\CompanyContext::class)->today()->toDateString()" required />
        <x-input name="assignment_reason" label="Reason" placeholder="e.g. Promotion, transfer" />
    </form>
    <x-slot:footer>
        <x-button variant="outline" @click="hide()">Cancel</x-button>
        <x-button type="submit" form="reassign-form">Save assignment</x-button>
    </x-slot:footer>
</x-modal>

{{-- Terminate: irreversible, so the consequences are spelled out. --}}
<x-modal name="terminate" title="Terminate employment" size="md" :open="$terminateOpen">
    <x-alert tone="danger" title="This cannot be undone" class="mb-5">
        <ul class="mt-1 list-disc space-y-1 pl-4">
            <li>The record becomes read-only and stays in the directory as history.</li>
            <li>Their login is disabled and mobile sessions are signed out immediately.</li>
            <li>The current assignment ends on the last working day.</li>
            <li>They can no longer check in or request leave.</li>
        </ul>
    </x-alert>
    <form id="terminate-form" method="POST" action="{{ route('admin.employees.terminate', $employee) }}" class="space-y-4">
        @csrf
        <input type="hidden" name="_form" value="terminate">
        <x-input name="terminated_at" type="date" label="Last working day" :value="app(\App\Support\CompanyContext::class)->today()->toDateString()" required />
        <x-input name="reason" label="Reason" placeholder="e.g. Resigned, end of contract" maxlength="255" required />
        <x-checkbox name="confirm" label="I understand this cannot be undone" />
        @error('confirm')<p class="-mt-2 text-xs font-medium text-danger-ink">{{ $message }}</p>@enderror
    </form>
    <x-slot:footer>
        <x-button variant="outline" @click="hide()">Cancel</x-button>
        <x-button type="submit" form="terminate-form" variant="danger">Terminate employee</x-button>
    </x-slot:footer>
</x-modal>
