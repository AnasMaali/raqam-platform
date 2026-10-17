{{--
    Warns two minutes before the session ends. The countdown only displays what the server said:
      - a Livewire request that succeeded means the server saw activity, so it restarts;
      - the answer to the "I'm here" request carries the time left;
      - at zero the page first ASKS the server (a GET that does not count as activity) and goes on
        from the real time left if another tab kept the session alive;
      - tabs tell each other (BroadcastChannel, same origin only), so one tab's activity closes the
        dialog in all of them.
    The browser never extends anything by itself.
--}}
<div
    x-data="{
        open: false,
        left: {{ (int) $remaining }},
        deadline: Date.now() + {{ (int) $remaining }} * 1000,
        hardDeadline: Date.now() + {{ (int) $cap }} * 1000,
        warn: {{ (int) $warn }},
        idle: {{ (int) $idle }},
        keepAliveUrl: @js($keepAliveUrl),
        statusUrl: @js($statusUrl),
        loginUrl: @js($loginUrl),
        timer: null,
        channel: null,
        checking: false,
        init() {
            this.timer = setInterval(() => this.tick(), 1000)
            if ('BroadcastChannel' in window) {
                this.channel = new BroadcastChannel('raqam-admin-session')
                this.channel.onmessage = (event) => {
                    const message = event.data
                    if (message?.type === 'activity' || message?.type === 'sync') {
                        this.restart(Number(message.remaining), false)
                    } else if (message?.type === 'ended') {
                        this.leave(false)
                    }
                }
            }
            window.Livewire?.hook('request', ({ succeed, fail }) => {
                succeed(() => this.restart(this.idle, 'activity'))
                fail(({ status, preventDefault }) => {
                    if (status === 401 || status === 419) {
                        preventDefault()
                        this.leave(true)
                    }
                })
            })
        },
        restart(seconds, announce) {
            if (! Number.isFinite(seconds) || seconds <= 0) return
            seconds = Math.min(seconds, this.idle, Math.floor((this.hardDeadline - Date.now()) / 1000))
            this.deadline = Date.now() + seconds * 1000
            this.checking = false
            this.open = false
            if (announce) this.channel?.postMessage({ type: announce, remaining: seconds })
            this.tick()
        },
        tick() {
            this.left = Math.max(0, Math.ceil((this.deadline - Date.now()) / 1000))
            if (this.left === 0) {
                this.verify()
            } else if (this.left <= this.warn && ! this.open) {
                this.open = true
                this.$nextTick(() => this.$refs.stay?.focus())
            } else if (this.left > this.warn && this.open) {
                this.open = false
            }
        },
        headers() {
            return { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '', 'Accept': 'application/json' }
        },
        async verify() {
            if (this.checking) return
            this.checking = true
            try {
                const response = await fetch(this.statusUrl, { method: 'GET', credentials: 'same-origin', headers: this.headers() })
                if (response.ok) {
                    const remaining = (await response.json()).remaining
                    if (remaining > 0) return this.restart(remaining, 'sync')
                    return this.leave(true)
                }
                if (response.status === 401 || response.status === 419) return this.leave(true)
            } catch (e) {
                // Offline or the server did not answer: that is not an answer, so ask again shortly.
            }
            this.deadline = Date.now() + 5000
            this.checking = false
        },
        leave(announce) {
            clearInterval(this.timer)
            if (announce) this.channel?.postMessage({ type: 'ended' })
            window.location.href = this.loginUrl
        },
        async stay() {
            try {
                const response = await fetch(this.keepAliveUrl, { method: 'POST', credentials: 'same-origin', headers: this.headers() })
                if (response.ok) return this.restart((await response.json()).remaining, 'activity')
                if (response.status === 401 || response.status === 419) this.leave(true)
            } catch (e) {
                // The button stays; press it again.
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
