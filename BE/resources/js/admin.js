import './listbox';
// Alpine components for the admin shell. Registered before Alpine.start() (see app.js).
document.addEventListener('alpine:init', () => {
    const store = (key, fallback) => {
        try {
            const raw = window.localStorage.getItem(key);
            return raw === null ? fallback : JSON.parse(raw);
        } catch {
            return fallback;
        }
    };
    const save = (key, value) => {
        try {
            window.localStorage.setItem(key, JSON.stringify(value));
        } catch {
            /* storage unavailable (private mode): state simply is not remembered */
        }
    };

    // Sidebar: group open/closed state is remembered per browser.
    Alpine.data('sidebar', (activeGroup) => ({
        drawer: false,
        groups: store('peopleos.nav.groups', {}),
        init() {
            // The group of the current page is always open, whatever was stored.
            if (activeGroup) this.groups[activeGroup] = true;
            this.$watch('groups', (v) => save('peopleos.nav.groups', v));
        },
        isOpen(key) {
            return this.groups[key] !== false;
        },
        toggle(key) {
            this.groups[key] = !this.isOpen(key);
        },
    }));

    // Theme: 'light' | 'dark' | null (follow the OS). The initial value is applied inline in <head> to avoid a flash.
    Alpine.data('themeToggle', () => ({
        dark: document.documentElement.dataset.theme === 'dark',
        toggle() {
            this.dark = !this.dark;
            const value = this.dark ? 'dark' : 'light';
            document.documentElement.dataset.theme = value;
            save('peopleos.theme', value);
        },
    }));

    // Generic modal, opened with $dispatch('open-modal', { name, data }).
    Alpine.data('modal', (name, startOpen = false, initial = {}) => ({
        open: startOpen,
        data: initial,
        show(event) {
            if (event.detail.name !== name) return;
            this.data = event.detail.data || {};
            this.open = true;
            this.$nextTick(() => this.$refs.focus?.focus());
        },
        hide() {
            this.open = false;
        },
    }));

    // Auto-dismissing flash toast.
    Alpine.data('toast', (ms = 6000) => ({
        show: true,
        init() {
            setTimeout(() => (this.show = false), ms);
        },
    }));

    // Password helper for the "temporary password" field.
    Alpine.data('passwordField', () => ({
        visible: false,
        value: '',
        generate() {
            const chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            const bytes = new Uint32Array(14);
            window.crypto.getRandomValues(bytes);
            this.value = Array.from(bytes, (b) => chars[b % chars.length]).join('') + '#7';
            this.visible = true;
        },
    }));
});

// Submit buttons show a spinner and cannot be double-clicked; restored when the page is shown from bfcache.
document.addEventListener('submit', (event) => {
    if (event.defaultPrevented) return;
    event.target.querySelectorAll('button[type=submit].btn').forEach((btn) => {
        setTimeout(() => {
            btn.classList.add('is-loading');
            btn.setAttribute('aria-disabled', 'true');
            btn.style.pointerEvents = 'none';
        }, 0);
    });
});
window.addEventListener('pageshow', () => {
    document.querySelectorAll('.btn.is-loading').forEach((btn) => {
        btn.classList.remove('is-loading');
        btn.removeAttribute('aria-disabled');
        btn.style.pointerEvents = '';
    });
});

// Shift form preview only: the server recomputes every number with ShiftCalculator.
document.addEventListener('alpine:init', () => {
    const toMin = (t) => {
        if (!t || !/^\d{2}:\d{2}/.test(t)) return null;
        const [h, m] = t.split(':').map(Number);
        return h * 60 + m;
    };
    const span = (a, b) => {
        const s = toMin(a), e = toMin(b);
        if (s === null || e === null) return 0;
        const d = e - s;
        return d <= 0 ? d + 1440 : d;
    };
    window.Alpine.data('shiftForm', (state) => ({
        start: state.start,
        end: state.end,
        breaks: state.breaks,
        overnight() {
            const s = toMin(this.start), e = toMin(this.end);
            return s !== null && e !== null && e <= s;
        },
        span() {
            return span(this.start, this.end);
        },
        unpaid() {
            return this.breaks.filter((b) => !b.is_paid).reduce((sum, b) => sum + (b.start_time && b.end_time ? span(b.start_time, b.end_time) : 0), 0);
        },
        fmt(min) {
            return `${Math.floor(min / 60)}h ${String(min % 60).padStart(2, '0')}m`;
        },
    }));
});

// Leave detail drawer: fetches the escaped, server-rendered panel for one request.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('leaveDrawer', () => ({
        open: false,
        loading: false,
        html: '',
        title: '',
        url: null,
        async load({ url, title }) {
            this.url = url;
            this.title = title || 'Leave request';
            this.open = true;
            this.loading = true;
            try {
                const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' }, credentials: 'same-origin' });
                this.html = res.ok ? await res.text() : '<p class="py-10 text-center text-[13px] text-danger-ink">This request could not be loaded.</p>';
            } catch {
                this.html = '<p class="py-10 text-center text-[13px] text-danger-ink">Network error. Try again.</p>';
            } finally {
                this.loading = false;
            }
        },
    }));
});

// Document upload: drag and drop plus the same checks the server makes (size, extension) so the user learns early.
// The server still decides from the file content; this is a convenience, never the guard.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('fileDrop', ({ maxBytes, extensions, types = [], employeeFixed = false, initialType = '' }) => ({
        file: null,
        over: false,
        error: '',
        typeId: String(initialType || ''),
        employeeId: '',
        types,
        get type() {
            return this.types.find((t) => String(t.id) === String(this.typeId));
        },
        get hasEmployee() {
            return employeeFixed || String(this.employeeId) !== '';
        },
        get allowedTypes() {
            return this.types.filter((t) => (this.hasEmployee ? t.emp : t.company));
        },
        pick(files) {
            const f = files && files[0];
            this.error = '';
            if (!f) return;
            const ext = (f.name.split('.').pop() || '').toLowerCase();
            if (!extensions.includes(ext)) {
                this.error = `Only ${extensions.map((e) => e.toUpperCase()).join(', ')} files can be uploaded.`;
                this.clear();
                return;
            }
            if (f.size > maxBytes) {
                this.error = `The file is ${(f.size / 1048576).toFixed(1)} MB. The limit is ${(maxBytes / 1048576).toFixed(0)} MB.`;
                this.clear();
                return;
            }
            this.file = { name: f.name, size: f.size };
            const input = this.$refs.input;
            if (input.files[0] !== f) {
                const dt = new DataTransfer();
                dt.items.add(f);
                input.files = dt.files;
            }
            const title = this.$refs.title;
            if (title && title.value === '') title.value = f.name.replace(/\.[^.]+$/, '');
        },
        drop(event) {
            this.over = false;
            this.pick(event.dataTransfer.files);
        },
        clear() {
            this.file = null;
            this.$refs.input.value = '';
        },
        size() {
            if (!this.file) return '';
            return this.file.size > 1048576 ? `${(this.file.size / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(this.file.size / 1024))} KB`;
        },
    }));
});
