{{--
    Keeps an editor from losing a long article (decision 21.7 and the content-phase requirement):
      - every 30 seconds, if the text of a draft changed, the page asks the server to save it (a real request,
        so it counts as activity for the session);
      - every 2 seconds the form is copied to this browser (localStorage, this user, this post), so if the session
        ends or the tab dies the text is still here after signing in again;
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
        init() { this.attach() },
        // The editor's own component, looked up by name each time and never kept in this object: the page makes
        // everything stored here reactive, and a reactive wrapper around $wire cannot be turned into JSON.
        w() { return window.Livewire?.all().find((c) => String(c.name).endsWith('.edit-post'))?.$wire },
        attach() {
            if (! this.w()) return setTimeout(() => this.attach(), 300)

            this.savedHash = this.backedUpHash = this.hash()
            this.look()

            window.Livewire.on('post-saved', () => { this.savedHash = this.hash(); this.forget(); this.note = '' })
            window.Livewire.on('post-autosaved', ({ at }) => { this.savedHash = this.sent; this.note = 'حُفظت المسودة تلقائياً ' + at })
            window.Livewire.on('post-autosave-failed', ({ message }) => { this.note = message })

            setInterval(() => this.autosave(), this.every)
            setInterval(() => this.copy(), 2000)
        },
        hash() { try { return JSON.stringify(this.w().data) ?? '' } catch (e) { return '' } },
        autosave() {
            const now = this.hash()
            if (! this.draft || now === this.savedHash || now === '') return
            this.sent = now
            this.w().autosave()
        },
        copy() {
            const now = this.hash()
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
        },
        dismiss() { this.offer = null; this.forget() },
        forget() { try { window.localStorage.removeItem(this.key) } catch (e) {} },
    }"
    x-cloak
    aria-live="polite"
>
    <div
        x-show="offer"
        role="alert"
        style="position:fixed;inset-inline:16px;bottom:16px;z-index:60;max-width:640px;margin-inline:auto;border-radius:12px;padding:14px 16px;background:#78350f;color:#fff;box-shadow:0 10px 30px rgba(0,0,0,.35);display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between"
    >
        <span>وجدنا نسخة محلية من هذه المادة أحدث من المحفوظة على الخادم (تعديلات لم تُحفظ). هل تريد استرجاعها؟</span>
        <span style="display:flex;gap:8px">
            <button type="button" x-on:click="restore()" style="background:#fff;color:#78350f;border-radius:8px;padding:6px 14px;font-weight:600">استرجاع</button>
            <button type="button" x-on:click="dismiss()" style="border:1px solid #fff;border-radius:8px;padding:6px 14px">تجاهل</button>
        </span>
    </div>
    <div
        x-show="note && ! offer"
        x-text="note"
        style="position:fixed;inset-inline-start:16px;bottom:16px;z-index:50;border-radius:8px;padding:6px 12px;font-size:13px;background:rgba(0,0,0,.75);color:#fff"
    ></div>
</div>
