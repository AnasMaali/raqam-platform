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
    let nextId = 1;
    const listeners = {};
    const events = {};
    const hooks = {};
    const calls = [];
    let reads = 0;
    const wire = {
        autosave: () => { calls.push('autosave'); return Promise.resolve(); },
        $get: (key) => wire[key],
        $set: (key, value) => { calls.push(['set', key]); wire[key] = value; },
    };
    // Every look at the whole form is counted: the script must not read a long article over and over.
    let form = data;
    Object.defineProperty(wire, 'data', { get: () => { reads++; return form; }, set: (v) => { form = v; }, enumerable: true });
    const view = {
        localStorage,
        Livewire: {
            // The real page has many components, and every $wire answers "a function" for any property (it is a proxy):
            // the script must pick the editor by name, not by "has an autosave method".
            all: () => [
                { name: 'filament.livewire.topbar', $wire: new Proxy({}, { get: () => () => {} }) },
                { name: 'filament.livewire.sidebar', $wire: new Proxy({}, { get: () => () => {} }) },
                { name: 'app.filament.resources.posts.pages.edit-post', $wire: wire },
                { name: 'filament.livewire.notifications', $wire: new Proxy({}, { get: () => () => {} }) },
            ],
            on: (name, cb) => { listeners[name] = cb; },
            hook: (name, cb) => { hooks[name] = cb; },
        },
        addEventListener: (name, cb) => { (events[name] ??= []).push(cb); },
    };
    const context = vm.createContext({
        window: view, Date: fakeDate, JSON, Math, Number, String,
        setTimeout: (fn, ms = 0) => { timers.push({ id: nextId, fn, ms, once: true }); return nextId++; },
        clearTimeout: (id) => { const i = timers.findIndex((t) => t.once && t.id === id); if (i >= 0) timers.splice(i, 1); },
        setInterval: (fn, ms) => { timers.push({ id: nextId, fn, ms, once: false }); return nextId++; },
    });
    // The page bakes the server's time and the draft flag into the script; the simulation changes them per tab.
    const src = source.replace(/^\(|\)$/g, '')
        .replace(/serverAt: \d+/, 'serverAt: ' + Math.floor(serverAt))
        .replace(/draft: (true|false)/, 'draft: ' + draft);
    const tab = vm.runInContext('(' + src + ')', context);
    tab.init();
    const intervals = timers.filter((t) => !t.once);
    // The editor does something on the page (a key, a click, a change, ...): the listeners on window hear it.
    const fire = (name = 'input') => (events[name] ?? []).forEach((cb) => cb({ type: name }));
    return {
        tab, wire, calls, listeners, intervals, view, hooks, events, fire,
        reads: () => reads,
        run: (ms) => intervals.filter((t) => t.ms === ms).forEach((t) => t.fn()),
        // The editor stops typing: the waiting look (300 ms) runs.
        pause: () => { const due = timers.filter((t) => t.once && t.ms <= 300); due.forEach((t) => timers.splice(timers.indexOf(t), 1)); due.forEach((t) => t.fn()); return due.length; },
        type: (value) => { wire.data = value; fire('input'); },
    };
}

let failed = 0;
const check = (condition, message) => { console.log((condition ? 'ok   ' : 'FAIL ') + message); if (!condition) failed++; };
const KEY = 'raqam:post:1:42';

// --- a draft being typed
let t = openTab();
check(t.intervals.length === 1 && t.intervals[0].ms === 30000, 'one timer only, the 30 s autosave: nothing looks at the form every second');
check(['input', 'change', 'click', 'keyup'].every((n) => t.events[n]?.length === 1) && typeof t.hooks.commit === 'function', 'it listens for input, change, click, keys and the server\'s round trips');
check(t.view.raqamPostState?.dirty === false, 'nothing typed yet: the bar says there is nothing unsaved');
t.run(30000);
check(t.calls.length === 0, 'nothing changed: autosave sends nothing');
let before = t.reads();
t.pause();
check(t.reads() === before, 'nothing happens: nothing reads the form');
t.type({ title: 'أ', body: '<p>ن</p>' });
for (let i = 0; i < 40; i++) t.type({ title: 'أ', body: '<p>نص جديد ' + i + '</p>' });
check(t.view.raqamPostState.dirty === false && t.reads() === before, 'while typing nothing is read yet: the look waits for a pause');
check(t.pause() === 1, 'forty keys leave one waiting look, not forty');
check(t.reads() === before + 1, 'and that look reads the form once, for the bar and the local copy together');
check(t.view.raqamPostState.dirty === true, 'typed: after the pause the bar says there are unsaved changes');
t.run(30000);
check(t.calls.join() === 'autosave', 'the text changed: autosave asks the server once');
t.listeners['post-autosaved']({ at: '10:15:00' });
check(t.view.raqamPostState.dirty === false && t.view.raqamPostState.note.includes('10:15:00'), 'autosaved: the bar says so, with the time');
t.run(30000);
check(t.calls.length === 1, 'saved: the same text is not sent again');
t.type({ title: 'أ', body: '<p>نص أطول</p>' });
t.run(30000);
check(t.calls.length === 2, 'changed again: sent again');

// --- a value set by the server (an uploaded picture) is noticed after its round trip
t = openTab();
t.wire.data = { title: 'أ', body: '<p>ب</p>', cover: 7 };
let done;
t.hooks.commit({ succeed: (cb) => { done = cb; } });
done();
t.pause();
check(t.view.raqamPostState.dirty === true, 'a round trip that changed the form is noticed too');
t.wire.data = { title: 'أ', body: '<p>ب</p>' };
t.fire('change');
t.pause();
check(t.view.raqamPostState.dirty === false, 'and changing it back clears the bar again');

// --- a request is not repeated for the same text, and a published item is never asked for
t = openTab();
t.type({ title: 'أ', body: '<p>تعديل لم يُحفظ</p>' });
t.run(30000); t.run(30000); t.run(30000);
check(t.calls.length === 1, 'the same unsaved text is sent once, not every 30 seconds (that would keep the session alive for ever)');
t.listeners['post-autosave-failed']({ message: 'x' });
t.run(30000);
check(t.calls.length === 2, 'after a failure the same text is tried again');
t = openTab();
t.wire.data = { title: 'أ', body: '<p>نص</p>', status: 'published' };
t.listeners['post-saved']();
t.wire.data = { title: 'أ', body: '<p>نص آخر</p>', status: 'published' };
t.run(30000);
check(t.calls.length === 0, 'once the item is saved as published, autosave stops asking');

// --- the local copy
t = openTab();
t.fire('click'); t.pause();
check(!store.has(KEY), 'unchanged: no local copy is written');
t.type({ title: 'ع', body: '<p>كتبت شيئاً</p>' });
t.pause();
const copy = JSON.parse(store.get(KEY));
check(copy.data.title === 'ع' && copy.at === now, 'a change is copied to the browser with the time');
t.listeners['post-saved']();
check(!store.has(KEY), 'a successful save removes the local copy');
t.fire('keyup'); t.pause();
check(!store.has(KEY), 'and the saved text is not copied again');

// --- a published item is never autosaved, but still copied
t = openTab({ draft: false });
t.type({ title: 'منشور', body: '<p>تعديل</p>' });
t.run(30000);
check(t.calls.length === 0, 'a published item is never autosaved');
t.pause();
check(store.has(KEY), 'but its unsaved edits are still copied to the browser');
store.clear();

// --- coming back
store.set(KEY, JSON.stringify({ at: now + 5000, data: { title: 'من النسخة', body: '<p>x</p>' } }));
t = openTab({ serverAt: now / 1000 - 100 });
check(t.tab.offer?.data.title === 'من النسخة', 'a copy newer than the server is offered back');
t.tab.restore();
check(t.calls.some((c) => c[0] === 'set' && c[1] === 'data') && t.wire.data.title === 'من النسخة' && t.tab.offer === null, 'restoring puts it in the form');
t.pause();
check(t.view.raqamPostState.dirty === true, 'and the bar then says it is not saved yet');
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
t.type({ title: 'ممتلئ', body: '' });
let threw = false;
try { t.pause(); } catch (e) { threw = true; }
check(!threw, 'a full or blocked storage does not break anything');
localStorage.full = false;

// --- the page without the editor component yet
const early = openTab();
check(typeof early.tab.attach === 'function', 'it waits for the editor component to appear');

console.log(failed ? `\n${failed} FAILED` : '\nall ok');
process.exit(failed ? 1 : 0);
