{{-- One shared confirmation dialog. Trigger with:
     $dispatch('open-modal', { name: 'confirm', data: { title, text, action, method: 'DELETE', label: 'Delete' } }) --}}
<x-modal name="confirm" size="sm" title="Are you sure?">
    <form method="POST" :action="data.action" x-ref="form" id="confirm-form">
        @csrf
        <input type="hidden" name="_method" :value="data.method || 'POST'">
        <template x-for="(value, key) in (data.fields || {})" :key="key"><input type="hidden" :name="key" :value="value"></template>
        <template x-if="data.text"><p class="text-[13.5px] leading-relaxed text-ink-2" x-text="data.text"></p></template>
    </form>
    <x-slot:footer>
        <x-button variant="outline" @click="hide()">Cancel</x-button>
        <button type="submit" form="confirm-form" class="btn btn-md" :class="data.danger === false ? 'btn-primary' : 'btn-danger'" x-text="data.label || 'Confirm'"></button>
    </x-slot:footer>
</x-modal>
