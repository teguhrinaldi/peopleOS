{{--
    PeopleOS listbox. Wrap ONE native <select> with it: the select stays in the DOM as the real form control
    (name, value, required, x-model, change events), and Alpine replaces what the user sees with an accessible
    combobox + popover (bottom sheet on phones). If Alpine never starts, the native select is simply visible.
    Options are read from the select, so server-rendered lists, <optgroup>s and x-for options all work.
    Optional attributes on the <select>: data-search="always|never" (default: shown above 7 options),
    data-options-url (JSON [{value,label}] loaded on first open, with loading / error / retry states).
--}}
<div x-data="listbox" {{ $attributes->class(['lb relative']) }}>
    {{ $slot }}

    <button type="button" x-ref="trigger" x-cloak x-show="ready" class="ctl lb-trigger" :class="{ 'is-open': open }"
        :id="uid + '-t'" role="combobox" aria-haspopup="listbox" :aria-expanded="open ? 'true' : 'false'" :aria-controls="uid + '-l'"
        :aria-labelledby="labelledBy" :aria-invalid="invalid ? 'true' : null" :aria-required="required ? 'true' : null"
        :disabled="disabled" @click="toggle()" @keydown="onTriggerKey($event)">
        <span class="lb-value" :class="{ 'is-placeholder': !hasValue }" x-text="display"></span>
        <svg class="lb-chevron" :class="{ 'is-open': open }" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
    </button>

    {{-- The layer is position:fixed, so table/card/dialog overflow never clips it; it flips above the trigger near the bottom edge. --}}
    <div x-ref="layer" x-show="open" x-cloak class="lb-layer" :class="{ 'is-sheet': sheet }" @click.self="close(true)" @keydown="onPanelKey($event)">
        <div x-ref="panel" x-show="open" x-transition.opacity.duration.120ms class="lb-panel" :class="{ 'is-sheet': sheet }" :style="panelStyle">
            <div class="lb-sheet-head" x-show="sheet">
                <span class="lb-sheet-title" x-text="title"></span>
                <button type="button" class="lb-close" @click="close(true)" aria-label="Close"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" width="16" height="16"><path d="M18 6 6 18M6 6l12 12" /></svg></button>
            </div>
            <div class="lb-search" x-show="searchable">
                <input type="text" x-ref="search" x-model="q" @input="active = filtered.length ? 0 : -1; refreshScroll()" class="ctl" autocomplete="off" spellcheck="false"
                    role="searchbox" :aria-label="'Search ' + title" :aria-controls="uid + '-l'" :aria-activedescendant="activeId" placeholder="Search…">
            </div>
            <ul x-ref="list" :id="uid + '-l'" role="listbox" tabindex="-1" class="lb-list" :aria-label="title" :aria-activedescendant="activeId" :aria-busy="loading ? 'true' : 'false'">
                <template x-for="(it, i) in filtered" :key="it.key">
                    <li role="option" :id="uid + '-o' + i" :aria-selected="it.value === value ? 'true' : 'false'" :aria-disabled="it.disabled ? 'true' : null"
                        class="lb-option" :class="{ 'is-active': i === active, 'is-selected': it.value === value, 'is-disabled': it.disabled, 'is-group-start': it.groupStart }"
                        @click="choose(it)" @mousemove="if (!it.disabled) active = i">
                        <span class="lb-group" x-show="it.groupStart" x-text="it.group"></span>
                        <span class="lb-option-row">
                            <span class="lb-option-label" x-text="it.label"></span>
                            <svg x-show="it.value === value" class="lb-check" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5" /></svg>
                        </span>
                    </li>
                </template>
            </ul>
            <p class="lb-state" x-show="loading" role="status">Loading…</p>
            <p class="lb-state is-error" x-show="error" role="alert"><span>Options could not be loaded.</span> <button type="button" class="lb-retry" @click="loadRemote(true)">Try again</button></p>
            <p class="lb-state" x-show="!loading && !error && filtered.length === 0" role="status" x-text="q ? 'No matches for “' + q + '”' : 'No options available'"></p>
        </div>
    </div>
</div>
