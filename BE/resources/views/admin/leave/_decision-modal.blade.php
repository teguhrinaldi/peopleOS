{{-- Approve / reject (reject needs a note) and cancel. Opened from the list and from the detail panel. --}}
<x-modal name="leave-decision" title="Review leave" :open="$reopen ?? false"
    :initial="['action' => old('_action'), 'decision' => old('decision', 'approve'), 'note' => old('note', ''), 'title' => 'Review leave']">
    <form id="leave-decision-form" method="POST" :action="data.action" class="space-y-4">
        @csrf
        <input type="hidden" name="_form" value="leave-decision">
        <input type="hidden" name="_action" :value="data.action">
        <input type="hidden" name="decision" :value="data.decision">
        <p class="text-[13.5px] text-ink-2" x-show="data.decision === 'approve'">The reserved days become used and the employee is notified.</p>
        <p class="text-[13.5px] text-ink-2" x-show="data.decision === 'reject'">The reserved days go back to the balance. The employee sees your reason.</p>
        <x-mfield name="note" as="textarea" label="Note" :rows="3" helper="Required when rejecting." />
    </form>
    <x-slot:footer>
        <x-button variant="outline" @click="hide()">Cancel</x-button>
        <button type="submit" form="leave-decision-form" class="btn btn-md" :class="data.decision === 'reject' ? 'btn-danger' : 'btn-primary'" x-text="data.decision === 'reject' ? 'Reject' : 'Approve'"></button>
    </x-slot:footer>
</x-modal>

<x-modal name="leave-cancel" title="Cancel leave" size="sm">
    <form id="leave-cancel-form" method="POST" :action="data.action" class="space-y-4">
        @csrf
        <p class="text-[13.5px] text-ink-2">The days are returned to the balance. Approved leave can only be cancelled before it starts.</p>
        <x-mfield name="reason" as="textarea" label="Reason (optional)" :rows="2" />
    </form>
    <x-slot:footer>
        <x-button variant="outline" @click="hide()">Keep it</x-button>
        <x-button type="submit" form="leave-cancel-form" variant="danger">Cancel leave</x-button>
    </x-slot:footer>
</x-modal>
