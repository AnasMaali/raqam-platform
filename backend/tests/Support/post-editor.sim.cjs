// Runs the REAL x-data object of the editor's autosave / local-copy script against a fake clock, a fake
// localStorage and a fake Livewire component. Usage: node post-editor.sim.cjs <file with the object literal>
const fs = require('fs');
const vm = require('vm');

const source = fs.readFileSync(process.argv[2], 'utf8');

let now = 1_700_000_000_000;
const fakeDate = { now: () => now };

const store = new Map();
const localStorage = {
    getItem: (k) => (store.has(k) ? store.get(k) : null),
    setItem: (k, v) => { if (localStorage.full) throw new Error('quota'); store.set(k, v); },
    removeItem: (k) => store.delete(k),
    full: false,
};

function openTab({ draft = true, serverAt = 1_699_999_000, data = { title: 'أ', body: '<p>ب</p>' } } = {}) {
    const timers = [];
    const listeners = {};
    const calls = [];
    const wire = {
        data,
        autosave: () => { calls.push('autosave'); return Promise.resolve(); },
        $set: (key, value) => { calls.push(['set', key]); wire[key] = value; },
    };
    const view = {
        localStorage,
        Livewire: {
            all: () => [{ $wire: wire }],
            on: (name, cb) => { listeners[name] = cb; },
        },
    };
    const context = vm.createContext({
        window: view, Date: fakeDate, JSON, Math, Number, String,
        setTimeout: (fn) => { timers.push(fn); return timers.length; },
        setInterval: (fn, ms) => { timers.push({ fn, ms }); return timers.length; },
    });
    // The page bakes the server's time and the draft flag into the script; the simulation changes them per tab.
    const src = source.replace(/^\(|\)$/g, '')
        .replace(/serverAt: \d+/, 'serverAt: ' + Math.floor(serverAt))
        .replace(/draft: (true|false)/, 'draft: ' + draft);
    const tab = vm.runInContext('(' + src + ')', context);
    tab.init();
    const intervals = timers.filter((t) => typeof t === 'object');
    return {
        tab, wire, calls, listeners, intervals,
        run: (ms) => intervals.filter((t) => t.ms === ms).forEach((t) => t.fn()),
    };
}

let failed = 0;
const check = (condition, message) => { console.log((condition ? 'ok   ' : 'FAIL ') + message); if (!condition) failed++; };
const KEY = 'raqam:post:1:42';

// --- a draft being typed
let t = openTab();
check(t.intervals.length === 2 && t.intervals.map((i) => i.ms).sort((a, b) => a - b).join() === '2000,30000', 'two timers: a 2 s local copy and a 30 s autosave');
t.run(30000);
check(t.calls.length === 0, 'nothing changed: autosave sends nothing');
t.wire.data = { title: 'أ', body: '<p>نص جديد</p>' };
t.run(30000);
check(t.calls.join() === 'autosave', 'the text changed: autosave asks the server once');
t.listeners['post-autosaved']({ at: '10:15:00' });
t.run(30000);
check(t.calls.length === 1, 'saved: the same text is not sent again');
t.wire.data = { title: 'أ', body: '<p>نص أطول</p>' };
t.run(30000);
check(t.calls.length === 2, 'changed again: sent again');

// --- the local copy
t = openTab();
t.run(2000);
check(!store.has(KEY), 'unchanged: no local copy is written');
t.wire.data = { title: 'ع', body: '<p>كتبت شيئاً</p>' };
t.run(2000);
const copy = JSON.parse(store.get(KEY));
check(copy.data.title === 'ع' && copy.at === now, 'a change is copied to the browser with the time');
t.listeners['post-saved']();
check(!store.has(KEY), 'a successful save removes the local copy');
t.run(2000);
check(!store.has(KEY), 'and the saved text is not copied again');

// --- a published item is never autosaved, but still copied
t = openTab({ draft: false });
t.wire.data = { title: 'منشور', body: '<p>تعديل</p>' };
t.run(30000);
check(t.calls.length === 0, 'a published item is never autosaved');
t.run(2000);
check(store.has(KEY), 'but its unsaved edits are still copied to the browser');
store.clear();

// --- coming back
store.set(KEY, JSON.stringify({ at: now + 5000, data: { title: 'من النسخة', body: '<p>x</p>' } }));
t = openTab({ serverAt: now / 1000 - 100 });
check(t.tab.offer?.data.title === 'من النسخة', 'a copy newer than the server is offered back');
t.tab.restore();
check(t.calls.some((c) => c[0] === 'set' && c[1] === 'data') && t.wire.data.title === 'من النسخة' && t.tab.offer === null, 'restoring puts it in the form');
t.tab.dismiss();
check(!store.has(KEY), 'dismissing removes it');

store.set(KEY, JSON.stringify({ at: now - 5000, data: { title: 'قديمة', body: '' } }));
t = openTab({ serverAt: now / 1000 + 10 });
check(t.tab.offer === null && !store.has(KEY), 'a copy older than the server is dropped, not offered');

store.set(KEY, JSON.stringify({ at: now - 30 * 24 * 3600 * 1000, data: { title: 'عمرها شهر', body: '' } }));
t = openTab({ serverAt: 0 });
check(t.tab.offer === null && !store.has(KEY), 'a copy older than two weeks is dropped');

store.set(KEY, 'not json {');
t = openTab();
check(t.tab.offer === null && !store.has(KEY), 'a broken copy is dropped without breaking the page');

store.set(KEY, JSON.stringify({ at: now + 1, data: { title: 'أ', body: '<p>ب</p>' } }));
t = openTab({ serverAt: 0, data: { title: 'أ', body: '<p>ب</p>' } });
check(t.tab.offer === null, 'a copy identical to the form is not offered');

// --- storage trouble must not hurt
localStorage.full = true;
t = openTab();
t.wire.data = { title: 'ممتلئ', body: '' };
let threw = false;
try { t.run(2000); } catch (e) { threw = true; }
check(!threw, 'a full or blocked storage does not break anything');
localStorage.full = false;

// --- the page without the editor component yet
const early = openTab();
check(typeof early.tab.attach === 'function', 'it waits for the editor component to appear');

console.log(failed ? `\n${failed} FAILED` : '\nall ok');
process.exit(failed ? 1 : 0);
