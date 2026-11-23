{{--
    Keeps an editor from losing a long article (decision 21.7 and the content-phase requirement):
      - every 30 seconds, if the text of a draft changed, the page asks the server to save it (a real request,
        so it counts as activity for the session);
      - once the editor stops typing for a moment (300 ms after the last input, change, click or key, or a round trip to
        the server), the form is compared with what was last saved: the bar says whether there are unsaved changes, and a
        changed form is copied to this browser (localStorage, this user, this post), so if the session ends or the tab
        dies the text is still here after signing in again. Nothing runs while nothing happens, so a long article on a
        phone is not read over and over;
      - on the next visit, a copy newer than the server's is offered back.
    Nothing is sent anywhere by the local copy; it is removed after every successful save.
--}}
<div
    x-data="{
        key: @js($key),
        serverAt: {{ (int) $serverAt }},
        draft: @js($isDraft),
        every: 30000,
        keep: 14 * 24 * 3600 * 1000,
        sent: '',
        savedHash: '',
        backedUpHash: '',
        offer: null,
        note: '',
        pause: 300,
        timer: null,
        init() { this.attach() },
        // The editor's own component, looked up by name each time and never kept in this object: the page makes
        // everything stored here reactive, and a reactive wrapper around $wire cannot be turned into JSON.
        w() { return window.Livewire?.all().find((c) => String(c.name).endsWith('.edit-post'))?.$wire },
        attach() {
            if (! this.w()) return setTimeout(() => this.attach(), 300)

            this.savedHash = this.backedUpHash = this.hash()
            this.look()

            window.Livewire.on('post-saved', () => { this.savedHash = this.hash(); this.forget(); this.note = ''; this.draft = this.w().data?.status === 'draft'; this.announce() })
            window.Livewire.on('post-autosaved', ({ at }) => { this.savedHash = this.sent; this.note = 'حُفظت المسودة تلقائياً ' + at; this.announce() })
            window.Livewire.on('post-autosave-failed', ({ message }) => { this.sent = ''; this.note = message; this.announce() })

            for (const name of ['input', 'change', 'click', 'keyup']) window.addEventListener(name, () => this.changed(), true)
            // A value set by the server (an uploaded picture, a filled address) arrives with a round trip, not an input.
            window.Livewire.hook?.('commit', ({ succeed }) => succeed(() => this.changed()))
            setInterval(() => this.autosave(), this.every)
            this.announce()
        },
        // Something may have changed: look once the editor pauses, not on every key.
        changed() {
            clearTimeout(this.timer)
            this.timer = setTimeout(() => this.check(), this.pause)
        },
        check() {
            const now = this.hash()
            this.announce(now)
            this.copy(now)
        },
        // The bar under the form says whether there are changes not saved yet (post-save-state.blade.php).
        announce(now = this.hash()) {
            const state = { dirty: now !== this.savedHash, note: this.note }
            window.raqamPostState = state
            if (typeof window.dispatchEvent === 'function') window.dispatchEvent(new CustomEvent('raqam-post-state', { detail: state }))
        },
        hash() { try { return JSON.stringify(this.w().data) ?? '' } catch (e) { return '' } },
        autosave() {
            const now = this.hash()
            // The same text is asked for once: a page that pinged the server every 30 seconds for an unsaved change would keep the session alive for ever.
            if (! this.draft || now === this.savedHash || now === this.sent || now === '') return
            this.sent = now
            this.w().autosave()
        },
        copy(now = this.hash()) {
            if (now === this.backedUpHash || now === this.savedHash || now.length > 2000000) return
            try {
                window.localStorage.setItem(this.key, JSON.stringify({ at: Date.now(), data: JSON.parse(now) }))
                this.backedUpHash = now
            } catch (e) { /* storage full or blocked: the server copy still works */ }
        },
        look() {
            try {
                const saved = JSON.parse(window.localStorage.getItem(this.key) ?? 'null')
                if (! saved || Date.now() - saved.at > this.keep) return this.forget()
                if (saved.at / 1000 > this.serverAt && JSON.stringify(saved.data) !== this.hash()) this.offer = saved
                else this.forget()
            } catch (e) { this.forget() }
        },
        restore() {
            this.w().$set('data', this.offer.data)
            this.offer = null
            this.note = 'استُرجعت النسخة المحلية. راجعها ثم احفظ.'
            this.changed()
        },
        dismiss() { this.offer = null; this.forget() },
        forget() { try { window.localStorage.removeItem(this.key) } catch (e) {} },
    }"
    x-cloak
    aria-live="polite"
>
    <div x-show="offer" role="alert" class="raqam-restore">
        <span>وجدنا نسخة محلية من هذه المادة أحدث من المحفوظة على الخادم (تعديلات لم تُحفظ). هل تريد استرجاعها؟</span>
        <span class="raqam-restore-actions">
            <x-filament::button type="button" size="sm" x-on:click="restore()">استرجاع</x-filament::button>
            <x-filament::button type="button" size="sm" color="gray" x-on:click="dismiss()">تجاهل</x-filament::button>
        </span>
    </div>
</div>
