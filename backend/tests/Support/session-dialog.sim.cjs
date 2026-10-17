// Runs the REAL x-data object of the session dialog (rendered by the test) against a fake clock, a
// fake server and two fake tabs. Usage: node session-dialog.sim.cjs <file exporting () => object>
const fs = require('fs');
const vm = require('vm');

const source = fs.readFileSync(process.argv[2], 'utf8');   // the object literal, as the page holds it

let now = 1_000_000;
const fakeDate = { now: () => now };

const bus = [];                                           // every BroadcastChannel of "the browser"
class FakeChannel {
    constructor(name) { this.name = name; bus.push(this); }
    postMessage(data) { bus.filter((c) => c !== this && c.name === this.name).forEach((c) => c.onmessage?.({ data })); }
}

const requests = [];                                      // every fetch, in order
let server = { status: 200, remaining: 1800, throws: false };
const fakeFetch = async (url, options) => {
    requests.push({ url, method: options.method, csrf: options.headers['X-CSRF-TOKEN'] });
    if (server.throws) throw new Error('offline');
    return { ok: server.status === 200, status: server.status, json: async () => ({ remaining: server.remaining }) };
};

const storage = new Map();                                // the browser's localStorage, shared by its tabs
const localStorage = { getItem: (k) => (storage.has(k) ? storage.get(k) : null), setItem: (k, v) => storage.set(k, v), removeItem: (k) => storage.delete(k) };

/** One browser tab = one JavaScript context with its own window, as in a browser. */
function openTab(name, where = {}) {
    const timers = [];
    const hooks = {};
    const view = { localStorage, location: { href: '/page', pathname: where.pathname, search: where.search ?? '', replace(path) { this.replaced = path; } }, Livewire: { hook: (n, cb) => { hooks[n] = cb; } }, BroadcastChannel: FakeChannel };
    const context = vm.createContext({
        window: view, Date: fakeDate, fetch: fakeFetch, BroadcastChannel: FakeChannel, JSON, Math, Number, String,
        document: { querySelector: () => ({ content: 'token' }), documentElement: { classList: { contains: () => false } } },
        setInterval: (fn) => { timers.push(fn); return timers.length; },
        clearInterval: () => {},
    });
    const tab = vm.runInContext(source, context);
    tab.$nextTick = (f) => f();
    tab.$refs = { stay: { focus() {} } };
    tab.init();
    return { name, tab, view, hooks, channel: tab.channel, tick: () => timers.forEach((f) => f()) };
}

let failed = 0;
const check = (condition, message) => { console.log((condition ? 'ok   ' : 'FAIL ') + message); if (!condition) failed++; };
const advance = (seconds, tabs) => { now += seconds * 1000; tabs.forEach((t) => t.tick()); };
const settle = () => new Promise((r) => setImmediate(r));
const LOGIN = '/p/login?expired=1';

(async () => {
    // --- one tab on its own
    let a = openTab("A");
    
    advance(1600, [a]);
    check(!a.tab.open, 'no warning with 200 s left');
    advance(90, [a]);
    check(a.tab.open && a.tab.clock === '01:50', 'the warning opens two minutes before the end');
    advance(60, [a]);
    check(a.tab.left === 50 && requests.length === 0, 'time passing alone extends nothing and sends nothing');

    // --- the button
    await a.tab.stay();
    check(requests.length === 1 && requests[0].method === 'POST' && requests[0].csrf === 'token', 'the button sends one real POST with the CSRF token');
    check(!a.tab.open && a.tab.left === 1800, 'and restarts from the answer');

    // --- zero: ask first, do not leave
    advance(1800, [a]);
    await settle();
    const status = requests[requests.length - 1];
    check(status.method === 'GET' && status.url.includes('/session/status') && status.csrf === 'token', 'at zero the page asks GET session/status with the CSRF token');
    check(a.view.location.href === '/page' || a.view.location.href === LOGIN, 'it asked before going anywhere');

    // --- another tab kept the session alive: the answer still has time left
    a = openTab('A'); requests.length = 0;
    server = { status: 200, remaining: 900, throws: false };
    advance(1800, [a]); await settle();
    check(a.view.location.href === '/page' && a.tab.left === 900 && !a.tab.open, 'if the server says time is left the tab goes on from the real time (900 s)');
    check(requests.filter((r) => r.method === 'POST').length === 0, 'asking never used the keep-alive request');

    // --- the session really ended
    for (const [status, label] of [[419, '419'], [401, '401']]) {
        a = openTab('A');  server = { status, remaining: 0, throws: false };
        advance(1800, [a]); await settle();
        check(a.view.location.href === LOGIN, `a ${label} answer at zero goes to the sign-in page`);
    }
    a = openTab('A');  server = { status: 200, remaining: 0, throws: false };
    advance(1800, [a]); await settle();
    check(a.view.location.href === LOGIN, 'remaining 0 at zero goes to the sign-in page');

    // --- a busy server or no network is not an answer
    a = openTab('A');  server = { status: 429, remaining: 0, throws: false };
    advance(1800, [a]); await settle();
    check(a.view.location.href === '/page', '429 does not sign anyone out');
    a = openTab('A');  server = { status: 200, remaining: 0, throws: true };
    advance(1800, [a]); await settle();
    a.tick();
    check(a.view.location.href === '/page' && a.tab.left === 5, 'offline does not sign anyone out; it asks again in 5 s');

    // --- two tabs
    server = { status: 200, remaining: 1800, throws: false };
    const A = openTab('A'); const B = openTab('B');
    const both = [A, B];
     advance(1700, both);
    check(A.tab.open && B.tab.open, 'both tabs show the dialog');
    A.hooks.request({ succeed: (f) => f({}), fail: () => {} });
    check(!A.tab.open && !B.tab.open && B.tab.left === 1800, 'a successful request in tab A restarts and closes the dialog in tab B too');

    advance(1700, both);
    check(A.tab.open && B.tab.open, 'both show the dialog again');
     server.remaining = 1500;
    await B.tab.stay();
    check(!A.tab.open && !B.tab.open && A.tab.left === 1500, '"I\'m here" in tab B closes it in tab A with the server\'s time');

    // --- a message cannot make a tab live longer than the server allows
    A.channel?.postMessage?.({ type: 'activity', remaining: 999999 });
    B.channel.onmessage({ data: { type: 'activity', remaining: 999999 } });
    check(B.tab.left <= 1800, 'a broadcast cannot raise the countdown above the idle limit');
    B.channel.onmessage({ data: { type: 'activity', remaining: -5 } });
    B.channel.onmessage({ data: { type: 'activity', remaining: 'abc' } });
    check(B.tab.left > 0, 'nonsense in a broadcast is ignored');

    // --- one tab learns the session ended: the others follow
    const C = openTab('C'); const D = openTab('D');
     server = { status: 419, remaining: 0, throws: false };
    advance(1800, [C, D]); await settle();
    check(C.view.location.href === LOGIN && D.view.location.href === LOGIN, 'when one tab finds the session ended, the others leave too');

    // --- Livewire says the session is gone
    const E = openTab('E'); 
    let prevented = false;
    E.hooks.request({ succeed: () => {}, fail: (f) => f({ status: 419, preventDefault: () => { prevented = true; } }) });
    check(prevented && E.view.location.href === LOGIN, 'a 419 on a Livewire request goes to the sign-in page');

    // --- near the 12-hour limit the countdown cannot be stretched
    const F = openTab('F');
    F.tab.hardDeadline = now + 600 * 1000;
    F.tab.restart(1800, false);
    check(F.tab.left <= 600, 'a restart cannot go past the 12-hour limit');

    // --- signing in again brings the person back to what they were doing
    let g = openTab('G', { pathname: '/p/posts/42/edit', search: '?tab=x' });
    g.tab.leave(true);
    check(g.view.location.href === LOGIN && JSON.parse(storage.get('raqam:return')).path === '/p/posts/42/edit?tab=x', 'leaving remembers the page, for this panel only');
    let h = openTab('H', { pathname: '/p' });
    check(h.view.location.replaced === '/p/posts/42/edit?tab=x' && !storage.has('raqam:return'), 'the dashboard after signing in goes back to it, once');
    h = openTab('H2', { pathname: '/p' });
    check(h.view.location.replaced === undefined, 'and does not do it twice');

    bus.length = 0;   // the earlier tabs are closed
    openTab('I', { pathname: '/elsewhere/page' }).tab.leave(true);
    check(!storage.has('raqam:return'), 'a page outside the panel is not remembered');
    openTab('I2', { pathname: '/p/login' }).tab.leave(true);
    check(!storage.has('raqam:return'), 'nor is the sign-in page itself');

    for (const evil of ['//evil.example/x', '/other/panel', '/p/../../x\\y', 'https://evil.example', '/p//evil.example']) {
        storage.set('raqam:return', JSON.stringify({ path: evil, at: now }));
        const j = openTab('J', { pathname: '/p' });
        check(j.view.location.replaced === undefined, `a stored address that leaves the panel is never followed (${evil})`);
    }

    storage.set('raqam:return', JSON.stringify({ path: '/p/posts/1/edit', at: now - 13 * 3600 * 1000 }));
    check(openTab('K', { pathname: '/p' }).view.location.replaced === undefined, 'a remembered page older than twelve hours is dropped');

    storage.set('raqam:return', JSON.stringify({ path: '/p/posts/1/edit', at: now }));
    check(openTab('L', { pathname: '/p/posts' }).view.location.replaced === undefined && storage.has('raqam:return'), 'only the dashboard acts on it; other pages leave it alone');
    storage.set('raqam:return', 'not json');
    check(openTab('M', { pathname: '/p' }).view.location.replaced === undefined, 'a broken value does no harm');

    process.exit(failed ? 1 : 0);
})();
