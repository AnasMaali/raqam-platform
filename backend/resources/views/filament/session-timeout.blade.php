{{--
    Warns two minutes before the session ends. The countdown only displays what the server said: it is
    restarted by a Livewire request that succeeded (the server saw activity) or by the answer of the
    "I'm here" request. The browser never extends anything by itself.
--}}
<div
    x-data="{
        open: false,
        left: {{ (int) $remaining }},
        deadline: Date.now() + {{ (int) $remaining }} * 1000,
        warn: {{ (int) $warn }},
        keepAliveUrl: @js($keepAliveUrl),
        loginUrl: @js($loginUrl),
        timer: null,
        init() {
            this.timer = setInterval(() => this.tick(), 1000)
            window.Livewire?.hook('request', ({ succeed, fail }) => {
                succeed(() => this.restart({{ (int) $idle }}))
                fail(({ status, preventDefault }) => {
                    if (status === 401 || status === 419) {
                        preventDefault()
                        this.expire()
                    }
                })
            })
        },
        restart(seconds) {
            this.deadline = Date.now() + seconds * 1000
            this.open = false
            this.tick()
        },
        tick() {
            this.left = Math.max(0, Math.ceil((this.deadline - Date.now()) / 1000))
            if (this.left === 0) {
                this.expire()
            } else if (this.left <= this.warn && ! this.open) {
                this.open = true
                this.$nextTick(() => this.$refs.stay?.focus())
            }
        },
        expire() {
            clearInterval(this.timer)
            window.location.href = this.loginUrl
        },
        async stay() {
            try {
                const response = await fetch(this.keepAliveUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '', 'Accept': 'application/json' },
                })
                if (! response.ok) throw new Error('expired')
                this.restart((await response.json()).remaining)
            } catch (e) {
                this.expire()
            }
        },
        get clock() {
            return String(Math.floor(this.left / 60)).padStart(2, '0') + ':' + String(this.left % 60).padStart(2, '0')
        },
    }"
    x-show="open"
    x-cloak
    x-transition.opacity
    role="alertdialog"
    aria-modal="true"
    aria-labelledby="session-timeout-title"
    style="position:fixed;inset:0;z-index:100;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.5);padding:16px"
>
    <div
        :style="document.documentElement.classList.contains('dark') ? 'background:#18181b;color:#fafafa' : 'background:#fff;color:#18181b'"
        style="max-width:420px;width:100%;border-radius:12px;padding:24px;box-shadow:0 20px 40px rgba(0,0,0,.3);text-align:start"
    >
        <h2 id="session-timeout-title" style="margin:0 0 8px;font-size:18px;font-weight:600">ستنتهي جلستك قريباً</h2>
        <p style="margin:0 0 16px">لا يوجد نشاط منذ فترة. سيتم تسجيل خروجك خلال <strong dir="ltr" x-text="clock"></strong> إن لم تؤكد وجودك.</p>
        <x-filament::button x-ref="stay" x-on:click="stay()" style="width:100%">أنا هون</x-filament::button>
    </div>
</div>
