// Accessible listbox that replaces the native <select> popup (see components/listbox.blade.php).
// The native select stays in the DOM as the real form control; this component mirrors it and writes back
// value + bubbling input/change events, so form posts, GET filters, x-model and auto-submit keep working.
document.addEventListener('alpine:init', () => {
    let seq = 0;
    window.Alpine.data('listbox', () => ({
        uid: 'lb' + ++seq,
        native: null,
        ready: false,
        open: false,
        sheet: false,
        items: [],
        value: '',
        q: '',
        active: -1,
        typed: '',
        typedAt: 0,
        disabled: false,
        invalid: false,
        required: false,
        title: '',
        labelledBy: null,
        searchMode: 'auto',
        loading: false,
        error: false,
        loaded: false,
        panelStyle: '',
        timer: null,
        observer: null,
        docKey: null,
        onReflow: null,

        init() {
            this.native = this.$el.querySelector('select');
            if (!this.native) return;
            this.searchMode = this.native.dataset.search || 'auto';
            this.native.classList.add('lb-native');
            this.native.tabIndex = -1;
            this.native.setAttribute('aria-hidden', 'true');
            this.read();
            this.resolveLabel();
            this.ready = true;
            this.native.addEventListener('change', () => this.sync());
            this.native.addEventListener('focus', () => this.$refs.trigger && this.$refs.trigger.focus());
            this.observer = new MutationObserver(() => this.read());
            this.observer.observe(this.native, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled', 'aria-invalid', 'label'] });
            // x-model / form.reset() change .value without events; a cheap poll keeps the trigger honest.
            this.timer = setInterval(() => this.sync(), 250);
            this.onReflow = () => {
                if (this.open) this.place();
            };
        },

        destroy() {
            clearInterval(this.timer);
            if (this.observer) this.observer.disconnect();
        },

        // ---- mirror of the native select ---------------------------------------------------------
        read() {
            const items = [];
            let n = 0;
            const walk = (parent, group) => {
                let first = true;
                for (const el of parent.children) {
                    if (el.tagName === 'OPTGROUP') {
                        walk(el, el.label);
                    } else if (el.tagName === 'OPTION' && !el.hidden) {
                        items.push({ key: 'k' + n++, value: el.value, label: el.textContent.trim(), disabled: el.disabled, group, groupStart: !!group && first });
                        first = false;
                    }
                }
            };
            walk(this.native, null);
            this.items = items;
            this.sync();
        },

        sync() {
            if (!this.native) return;
            this.value = this.native.value;
            this.disabled = this.native.disabled;
            this.invalid = this.native.getAttribute('aria-invalid') === 'true';
            this.required = this.native.required;
        },

        resolveLabel() {
            const id = this.native.id;
            const label = id ? document.querySelector('label[for="' + id + '"]') : null;
            if (label && !label.id) label.id = this.uid + '-lab';
            this.title = (label ? label.textContent : this.native.getAttribute('aria-label') || '').replace('*', '').trim();
            this.labelledBy = label ? label.id + ' ' + this.uid + '-t' : null;
            if (!label && this.title) this.$nextTick(() => this.$refs.trigger.setAttribute('aria-label', this.title));
            if (label) {
                label.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.$refs.trigger.focus();
                });
            }
        },

        get current() {
            return this.items.find((i) => i.value === this.value);
        },
        get hasValue() {
            return !!this.current && this.current.value !== '';
        },
        get display() {
            return this.current ? this.current.label : '';
        },
        get searchable() {
            if (this.searchMode === 'always') return true;
            if (this.searchMode === 'never') return false;
            return this.items.length > 7;
        },
        get filtered() {
            const term = this.q.trim().toLowerCase();
            if (!term) return this.items;
            return this.items.filter((i) => i.label.toLowerCase().includes(term));
        },
        get activeId() {
            return this.open && this.active >= 0 && this.filtered[this.active] ? this.uid + '-o' + this.active : null;
        },

        // ---- open / close ------------------------------------------------------------------------
        toggle() {
            if (this.open) this.close(true);
            else this.show();
        },

        async show() {
            if (this.disabled || this.open) return;
            this.q = '';
            this.sheet = window.matchMedia('(max-width: 767px)').matches;
            this.open = true;
            if (this.native.dataset.optionsUrl && !this.loaded) this.loadRemote(false);
            const idx = this.filtered.findIndex((i) => i.value === this.value);
            this.active = idx >= 0 ? idx : this.filtered.findIndex((i) => !i.disabled);
            await this.$nextTick();
            this.place();
            (this.searchable && !this.sheet ? this.$refs.search : this.$refs.list).focus({ preventScroll: true });
            this.refreshScroll();
            this.docKey = (e) => {
                if (e.key === 'Tab') this.close(false);
            };
            document.addEventListener('keydown', this.docKey);
            window.addEventListener('resize', this.onReflow);
            window.addEventListener('scroll', this.onReflow, true);
        },

        close(refocus) {
            if (!this.open) return;
            this.open = false;
            document.removeEventListener('keydown', this.docKey);
            window.removeEventListener('resize', this.onReflow);
            window.removeEventListener('scroll', this.onReflow, true);
            if (refocus) this.$refs.trigger.focus({ preventScroll: true });
        },

        place() {
            if (this.sheet) {
                this.panelStyle = '';
                return;
            }
            const r = this.$refs.trigger.getBoundingClientRect();
            const vw = window.innerWidth;
            const vh = window.innerHeight;
            const gap = 6;
            const margin = 8;
            const width = Math.min(Math.max(r.width, 208), vw - margin * 2);
            const left = Math.max(margin, Math.min(r.left, vw - width - margin));
            const natural = Math.min(this.$refs.panel.scrollHeight, 360);
            const below = vh - r.bottom - gap - margin;
            const above = r.top - gap - margin;
            const flip = below < Math.min(natural, 220) && above > below;
            const maxH = Math.min(360, Math.max(120, flip ? above : below));
            this.panelStyle = 'left:' + left + 'px;width:' + width + 'px;max-height:' + maxH + 'px;' + (flip ? 'bottom:' + (vh - r.top + gap) + 'px;' : 'top:' + (r.bottom + gap) + 'px;');
        },

        // ---- choosing ----------------------------------------------------------------------------
        choose(item) {
            if (!item || item.disabled) return;
            this.native.value = item.value;
            this.value = item.value;
            this.native.dispatchEvent(new Event('input', { bubbles: true }));
            this.native.dispatchEvent(new Event('change', { bubbles: true }));
            this.close(true);
        },

        // ---- keyboard ----------------------------------------------------------------------------
        onTriggerKey(e) {
            if (this.disabled) return;
            if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(e.key)) {
                e.preventDefault();
                this.show();
            } else if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                this.show().then(() => this.typeahead(e.key));
            }
        },

        onPanelKey(e) {
            const inSearch = e.target === this.$refs.search;
            switch (e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    this.move(1);
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    this.move(-1);
                    break;
                case 'PageDown':
                    e.preventDefault();
                    this.move(8);
                    break;
                case 'PageUp':
                    e.preventDefault();
                    this.move(-8);
                    break;
                case 'Home':
                    if (!inSearch) {
                        e.preventDefault();
                        this.jump(0, 1);
                    }
                    break;
                case 'End':
                    if (!inSearch) {
                        e.preventDefault();
                        this.jump(this.filtered.length - 1, -1);
                    }
                    break;
                case 'Enter':
                    e.preventDefault();
                    this.choose(this.filtered[this.active]);
                    break;
                case ' ':
                    if (!inSearch) {
                        e.preventDefault();
                        this.choose(this.filtered[this.active]);
                    }
                    break;
                case 'Escape':
                    e.preventDefault();
                    e.stopPropagation(); // never also close the dialog the listbox lives in
                    this.close(true);
                    break;
                default:
                    if (!inSearch && e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
                        e.preventDefault();
                        this.typeahead(e.key);
                    }
            }
        },

        move(step) {
            const list = this.filtered;
            if (!list.length) return;
            const dir = step > 0 ? 1 : -1;
            let i = this.active;
            let remaining = Math.abs(step);
            while (remaining > 0) {
                let next = i + dir;
                while (next >= 0 && next < list.length && list[next].disabled) next += dir;
                if (next < 0 || next >= list.length) break;
                i = next;
                remaining--;
            }
            if (i < 0) i = list.findIndex((o) => !o.disabled);
            this.active = i;
            this.refreshScroll();
        },

        jump(start, dir) {
            const list = this.filtered;
            for (let i = start; i >= 0 && i < list.length; i += dir) {
                if (!list[i].disabled) {
                    this.active = i;
                    break;
                }
            }
            this.refreshScroll();
        },

        typeahead(ch) {
            const now = Date.now();
            this.typed = now - this.typedAt > 600 ? ch.toLowerCase() : this.typed + ch.toLowerCase();
            this.typedAt = now;
            const list = this.filtered;
            if (!list.length) return;
            const from = this.typed.length === 1 ? this.active + 1 : Math.max(this.active, 0);
            for (let n = 0; n < list.length; n++) {
                const i = (from + n) % list.length;
                if (!list[i].disabled && list[i].label.toLowerCase().startsWith(this.typed)) {
                    this.active = i;
                    break;
                }
            }
            this.refreshScroll();
        },

        refreshScroll() {
            this.$nextTick(() => {
                const opt = document.getElementById(this.uid + '-o' + this.active);
                if (opt) opt.scrollIntoView({ block: 'nearest' });
            });
        },

        // ---- remote options (data-options-url on the <select>) -----------------------------------
        async loadRemote(force) {
            if (this.loading || (this.loaded && !force)) return;
            this.loading = true;
            this.error = false;
            try {
                const res = await fetch(this.native.dataset.optionsUrl, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
                if (!res.ok) throw new Error('http ' + res.status);
                const rows = await res.json();
                const keep = [...this.native.options].filter((o) => o.value === '');
                this.native.innerHTML = '';
                keep.forEach((o) => this.native.appendChild(o));
                rows.forEach((r) => this.native.appendChild(new Option(r.label, r.value)));
                this.loaded = true;
                this.read();
            } catch (e) {
                this.error = true;
            } finally {
                this.loading = false;
            }
        },
    }));
});

// Dashboard attendance trend card: swaps the server-rendered chart partial when the range changes,
// with loading and error states (never fake numbers on failure).
document.addEventListener('alpine:init', () => {
    window.Alpine.data('trendCard', (url, days) => ({
        days,
        loading: false,
        async loadRange(next) {
            if (next) this.days = Number(next);
            this.loading = true;
            let html;
            try {
                const res = await fetch(url + '?days=' + this.days, { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' }, credentials: 'same-origin' });
                if (!res.ok) throw new Error('http ' + res.status);
                html = await res.text();
            } catch (e) {
                html = '<div class="flex flex-col items-center gap-3 rounded-xl bg-danger-soft px-6 py-12 text-center" role="alert"><p class="text-[14px] font-semibold text-danger-ink">The attendance trend could not be loaded</p><p class="max-w-sm text-[13px] text-ink-2">Check your connection and try again. No numbers are shown rather than made-up ones.</p><button type="button" class="btn btn-md btn-outline" @click="$dispatch(' + "'trend-retry'" + ')">Try again</button></div>';
            }
            this.$refs.body.innerHTML = html;
            window.Alpine.initTree(this.$refs.body);
            this.loading = false;
        },
    }));
});
