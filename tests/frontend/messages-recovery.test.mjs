import test from 'node:test';
import assert from 'node:assert/strict';
import { createPoller } from '../../resources/js/messages/polling.js';
import { onPage, loadOnPage } from '../../resources/js/ui/page.js';

// Load the actual page controller without mounting a page during module evaluation.
globalThis.document = Object.assign(new EventTarget(), { querySelector: () => null });
globalThis.window = new EventTarget();
const { setupMessages } = await import('../../resources/js/messages.js');

class Element extends EventTarget {
    constructor(text = '') {
        super(); this.textContent = text; this.value = text; this.hidden = false; this.disabled = false;
        this.dataset = {}; this.nodes = {}; this.lists = {}; this.style = { setProperty() {} };
        this.scrollHeight = 100; this.scrollTop = 100; this.clientHeight = 100; this.isConnected = true;
        this.classList = { add: () => {} };
    }
    querySelector(selector) { return this.nodes[selector] || null; }
    querySelectorAll(selector) { return this.lists[selector] || []; }
    replaceChildren(...children) { this.replaceCount = (this.replaceCount || 0) + 1; this.children = children; this.textContent = children.map(x => x.textContent || '').join(''); }
    contains(element) { return this.contained === element && element != null; }
    setAttribute(name, value) { this[name] = value; }
    focus() { this.focused = true; this.focusCount = (this.focusCount || 0) + 1; }
    remove() { this.isConnected = false; }
}
function fixture({ pollUrl = '/mensajes/10/actualizaciones', managed = false, deferredMount = false } = {}) {
    const keys = ['document', 'window', 'fetch', 'matchMedia', 'innerHeight', 'setTimeout', 'clearTimeout', 'FormData'];
    const previous = Object.fromEntries(keys.map(key => [key, globalThis[key]]));
    const doc = Object.assign(new EventTarget(), { visibilityState: 'visible', hasFocus: () => true, activeElement: null });
    const win = Object.assign(new EventTarget(), { location: { reload() {}, assign() {} } });
    const header = new Element('IDENTIDAD PRIVADA'), mobile = new Element('ENLACES PRIVADOS');
    let parses = 0;
    doc.querySelectorAll = () => [header, mobile];
    doc.getElementById = () => new Element();
    doc.createElement = () => ({ content: new Element('FRAGMENTO AUTORIZADO'), set innerHTML(value) { parses++; this.content.textContent = value; } });
    globalThis.document = doc; globalThis.window = win;
    globalThis.matchMedia = () => Object.assign(new EventTarget(), { matches: false });
    globalThis.innerHeight = 800;
    let timerId = 0;
    const timers = new Map();
    globalThis.setTimeout = (fn, delay) => { timers.set(++timerId, { fn, delay }); return timerId; };
    globalThis.clearTimeout = id => timers.delete(id);
    globalThis.FormData = class extends Map { constructor() { super(); } append(key, value) { this.set(key, value); } };
    const root = new Element(); root.dataset = { canSend: 'true', historical: 'false', pollUrl };
    const names = ['history', 'composer', 'information', 'info-dialog', 'draft', 'upload-form', 'send-status', 'poll-status', 'file-rules', 'messaging-recovery', 'conversation-list', 'information-body'];
    const nodes = Object.fromEntries(names.map(name => [name, new Element()]));
    for (const name of names) root.nodes[`[data-${name}]`] = nodes[name];
    const recovery = nodes['messaging-recovery']; recovery.hidden = true;
    const retry = new Element(), access = new Element(), title = new Element(), explanation = new Element();
    Object.assign(recovery.nodes, { '[data-read-retry]': retry, '[data-recovery-access]': access, '[data-recovery-title]': title, '[data-recovery-message]': explanation });
    const form = nodes.composer, send = new Element(), token = new Element('d5c101d0-2ae1-4321-9123-123456789012'), csrf = new Element('synthetic-session-token');
    form.elements = { client_token: token }; form.nodes['[data-send]'] = send; form.action = '/mensajes/10/mensajes';
    form.nodes['[name="_token"]'] = csrf;
    root.lists.input = [token, csrf];
    nodes.draft.value = 'BORRADOR PRIVADO'; nodes.history.textContent = 'HISTORIAL PRIVADO';
    nodes['conversation-list'].textContent = 'ALIAS PRIVADO'; nodes['information-body'].textContent = 'ARCHIVO PRIVADO';
    nodes.information.nodes.h2 = new Element();
    nodes['file-rules'].textContent = '{}';
    const dialog = nodes['info-dialog']; dialog.close = () => { dialog.open = false; dialog.dispatchEvent(new Event('close')); };
    const requests = [];
    globalThis.fetch = (url, options) => new Promise((resolve, reject) => requests.push({ url, options, resolve, reject }));
    const listeners = new AbortController(), cleanups = [];
    let finishImport, pageRoot = root;
    if (managed) {
        doc.querySelector = () => pageRoot;
        doc.body = { style: { overflow: '' } };
        if (deferredMount) {
            const ready = new Promise(resolve => { finishImport = resolve; });
            loadOnPage('[data-messaging]', async () => { await ready; onPage('[data-messaging]', setupMessages); });
        } else onPage('[data-messaging]', setupMessages);
    } else {
        setupMessages(root, {
            listen(target, name, callback) { target?.addEventListener(name, callback, { signal: listeners.signal }); },
            onCleanup(callback) { cleanups.push(callback); },
        });
    }
    const settle = () => new Promise(resolve => setImmediate(resolve));
    const tick = async () => { const [id, task] = timers.entries().next().value; timers.delete(id); task.fn(); await settle(); };
    const respond = async (index, status = 200, data = { history_html: 'NUEVO HISTORIAL', inbox_html: 'ALIAS', information_html: 'INFO', can_send: true }) => {
        const payload = { conversation_id: Number(pollUrl.match(/\/mensajes\/(\d+)/)[1]), ...data };
        requests[index].resolve({ ok: status >= 200 && status < 300, status, json: async () => payload }); await settle();
    };
    const cleanup = () => {
        if (managed) doc.dispatchEvent(new Event('turbo:before-render'));
        else { listeners.abort(); cleanups.forEach(fn => fn()); }
    };
    return { root, nodes, recovery, retry, access, title, requests, timers, header, mobile, token, csrf, send,
        parses: () => parses,
        fragments: () => ['history', 'conversation-list', 'information-body'].map(key => nodes[key].replaceCount || 0),
        finishImport: async () => { finishImport(); await settle(); },
        setPageRoot: value => { pageRoot = value; },
        tick, respond, settle, cleanup, focus: () => win.dispatchEvent(new Event('focus')),
        submit: () => form.dispatchEvent(new Event('submit', { cancelable: true })),
        restore() { cleanup(); for (const key of keys) globalThis[key] = previous[key]; },
    };
}

test('fragment measurements: identical polls skip parsing and only changed fragments are rebuilt', async t => {
    const f = fixture();
    try {
        const response = { history_html: 'H1', inbox_html: 'I1', information_html: 'F1', can_send: true };
        const measured = [];
        for (const change of [{}, {}, { history_html: 'H2' }, { inbox_html: 'I2' }, { information_html: 'F2' }, {}]) {
            Object.assign(response, change);
            const parses = f.parses(), counts = f.fragments();
            await f.tick(); await f.respond(f.requests.length - 1, 200, { ...response });
            measured.push({ parses: f.parses() - parses, replacements: f.fragments().map((n, i) => n - counts[i]) });
        }
        t.diagnostic(JSON.stringify(measured));
        assert.deepEqual(measured, [
            { parses: 3, replacements: [1, 1, 1] }, { parses: 0, replacements: [0, 0, 0] },
            { parses: 1, replacements: [1, 0, 0] }, { parses: 1, replacements: [0, 1, 0] },
            { parses: 1, replacements: [0, 0, 1] }, { parses: 0, replacements: [0, 0, 0] },
        ]);
        assert.equal(f.nodes.draft.value, 'BORRADOR PRIVADO');
        assert.equal(f.token.value, 'd5c101d0-2ae1-4321-9123-123456789012');
        assert.equal(f.requests.filter(r => r.options.method === 'POST').length, 0);
    } finally { f.restore(); }
});

const htmlResponse = (changes = {}) => ({ history_html: 'H1', inbox_html: 'I1', information_html: 'F1', can_send: true, ...changes });
async function manualRead(f, data = htmlResponse(), status = 200) {
    f.retry.dispatchEvent(new Event('click')); await f.settle();
    await f.respond(f.requests.length - 1, status, data);
}

test('equal authorized HTML preserves localized DOM, scroll and drafts but updates can_send and manual recovery', async () => {
    const f = fixture();
    try {
        await manualRead(f);
        f.nodes.history.textContent = 'FECHA LOCALIZADA'; f.nodes.history.scrollTop = 37;
        await manualRead(f, {}, 503); assert.equal(f.recovery.hidden, false);
        await manualRead(f, htmlResponse({ can_send: false }));
        assert.equal(f.parses(), 3); assert.deepEqual(f.fragments(), [1, 1, 1]);
        assert.equal(f.nodes.history.textContent, 'FECHA LOCALIZADA'); assert.equal(f.nodes.history.scrollTop, 37);
        assert.equal(f.recovery.hidden, true); assert.equal(f.timers.size, 1);
        assert.equal(f.nodes.draft.disabled, true); assert.equal(f.send.disabled, true);
        assert.equal(f.nodes.draft.value, 'BORRADOR PRIVADO'); assert.match(f.nodes['poll-status'].textContent, /historial accesible/);
        await manualRead(f); assert.equal(f.nodes.draft.disabled, false); assert.equal(f.parses(), 3);
    } finally { f.restore(); }
});

test('focused inbox and information defer new HTML without marking it as applied', async () => {
    const f = fixture();
    try {
        await manualRead(f);
        const focused = {}; document.activeElement = focused;
        f.nodes['conversation-list'].contained = focused; f.nodes['information-body'].contained = focused;
        const changed = htmlResponse({ history_html: 'H2', inbox_html: 'I2', information_html: 'F2' });
        await manualRead(f, changed); assert.deepEqual(f.fragments(), [2, 1, 1]);
        document.activeElement = null;
        await manualRead(f, changed); assert.deepEqual(f.fragments(), [2, 2, 2]); assert.equal(f.parses(), 6);
    } finally { f.restore(); }
});

test('open history/information details defer application until the same HTML can be applied safely', async () => {
    const f = fixture();
    try {
        await manualRead(f);
        f.nodes.history.nodes['details[open]'] = {}; f.nodes['information-body'].nodes['details[open]'] = {};
        const changed = htmlResponse({ history_html: 'H2', information_html: 'F2' });
        await manualRead(f, changed); assert.deepEqual(f.fragments(), [1, 1, 1]);
        delete f.nodes.history.nodes['details[open]']; delete f.nodes['information-body'].nodes['details[open]'];
        await manualRead(f, changed); assert.deepEqual(f.fragments(), [2, 1, 2]);
    } finally { f.restore(); }
});

test('a reconstructed target node cannot reuse the previous node comparison', async () => {
    const f = fixture();
    try {
        await manualRead(f);
        const replacement = new Element(); f.root.nodes['[data-information-body]'] = replacement;
        await manualRead(f); assert.equal(replacement.textContent, 'F1'); assert.equal(replacement.replaceCount, 1);
        assert.equal(f.parses(), 4); assert.deepEqual(f.fragments(), [1, 1, 1]);
    } finally { f.restore(); }
});

test('a response for a different or missing conversation never parses or updates any fragment', async () => {
    const f = fixture();
    try {
        await manualRead(f);
        for (const conversation_id of [20, undefined]) {
            await manualRead(f, htmlResponse({ conversation_id, history_html: 'AJENO' }));
            assert.equal(f.parses(), 3); assert.deepEqual(f.fragments(), [1, 1, 1]);
            assert.equal(f.nodes.history.textContent, 'H1'); assert.equal(f.recovery.hidden, false); assert.equal(f.timers.size, 0);
        }
    } finally { f.restore(); }
});

for (const change of ['conversation', 'security-token', 'composer', 'history', 'disconnected']) test(`a changed ${change} context retires a pending read before applying or revoking anything`, async () => {
    const f = fixture();
    try {
        await manualRead(f); await f.tick();
        if (change === 'conversation') f.root.dataset.pollUrl = '/mensajes/20/actualizaciones';
        if (change === 'security-token') f.csrf.value = 'new-session';
        if (change === 'composer') f.root.nodes['[data-composer]'] = new Element();
        if (change === 'history') f.root.nodes['[data-history]'] = new Element();
        if (change === 'disconnected') f.root.isConnected = false;
        await f.respond(1, 401);
        assert.equal(f.parses(), 3); assert.deepEqual(f.fragments(), [1, 1, 1]);
        assert.equal(f.root.replaceCount || 0, 0); assert.equal(f.header.textContent, 'IDENTIDAD PRIVADA');
        assert.equal(f.requests[1].options.signal.aborted, true); assert.equal(f.timers.size, 0);
        f.focus(); f.retry.dispatchEvent(new Event('click')); await f.settle(); assert.equal(f.requests.length, 2);
    } finally { f.restore(); }
});

test('context is checked again when the JSON body finishes after the session token changed', async () => {
    const f = fixture();
    try {
        await manualRead(f); await f.tick();
        let finish; const body = new Promise(resolve => { finish = resolve; });
        f.requests[1].resolve({ ok: true, status: 200, json: () => body }); await f.settle();
        f.csrf.value = 'new-session'; finish(htmlResponse({ conversation_id: 10, history_html: 'TARDIO' })); await f.settle();
        assert.equal(f.parses(), 3); assert.equal(f.nodes.history.textContent, 'H1'); assert.equal(f.timers.size, 0);
    } finally { f.restore(); }
});

test('a POST from a retired security context cannot purge or acknowledge the new context', async () => {
    const f = fixture();
    try {
        await manualRead(f); f.submit(); await f.settle();
        f.csrf.value = 'new-session'; await f.respond(1, 401);
        assert.equal(f.root.replaceCount || 0, 0); assert.equal(f.nodes.draft.value, 'BORRADOR PRIVADO');
        assert.equal(f.token.value, 'd5c101d0-2ae1-4321-9123-123456789012');
        assert.equal(f.timers.size, 0); assert.equal(f.requests[1].options.signal.aborted, true);
    } finally { f.restore(); }
});

test('a stale successful read cannot advance the applied baseline after an uncertain send', async () => {
    const f = fixture();
    try {
        await manualRead(f); await f.tick(); f.submit(); await f.settle();
        f.requests[2].reject(new TypeError('uncertain send')); await f.settle();
        await f.respond(1, 200, htmlResponse({ history_html: 'H2' }));
        assert.equal(f.parses(), 3); assert.equal(f.nodes.history.textContent, 'H1'); assert.equal(f.timers.size, 0);
        await manualRead(f, htmlResponse({ history_html: 'H2' }));
        assert.equal(f.parses(), 4); assert.deepEqual(f.fragments(), [2, 1, 1]);
        assert.equal(f.nodes.draft.readOnly, true); assert.equal(f.requests.filter(r => r.options.method === 'POST').length, 1);
    } finally { f.restore(); }
});

test('a new controller/conversation starts without the previous applied representation', async () => {
    const old = fixture({ managed: true }); let current;
    try {
        await manualRead(old); await old.tick(); old.cleanup();
        current = fixture({ managed: true, pollUrl: '/mensajes/20/actualizaciones' });
        await manualRead(current); assert.equal(current.parses(), 3);
        await manualRead(current); assert.equal(current.parses(), 3);
        await old.respond(1); assert.equal(old.parses(), 3); assert.deepEqual(current.fragments(), [1, 1, 1]);
    } finally { current?.restore(); old.restore(); }
});

test('BFCache disposes a controller with applied fragments and ignores a later successful body', async () => {
    const f = fixture();
    try {
        await manualRead(f); await f.tick();
        window.dispatchEvent(Object.assign(new Event('pagehide'), { persisted: true }));
        await f.respond(1); assert.equal(f.parses(), 3); assert.equal(f.timers.size, 0);
        assert.equal(f.root.textContent, ''); assert.equal(f.requests[1].options.signal.aborted, true);
    } finally { f.restore(); }
});

for (const code of [409, 429, 503]) test(`${code} cannot update an applied baseline and manual recovery still works with equal HTML`, async () => {
    const f = fixture();
    try {
        await manualRead(f); await manualRead(f, htmlResponse({ history_html: 'ERROR' }), code);
        assert.equal(f.parses(), 3); assert.equal(f.nodes.history.textContent, 'H1'); assert.equal(f.timers.size, 0);
        await manualRead(f); assert.equal(f.parses(), 3); assert.equal(f.recovery.hidden, true); assert.equal(f.timers.size, 1);
    } finally { f.restore(); }
});

for (const code of [401, 403, 404, 419]) test(`applied fragments never suppress P1 ${code} after an uncertain send`, async () => {
    const f = fixture();
    try {
        await manualRead(f); await f.tick(); f.submit(); await f.settle();
        f.requests[2].reject(new TypeError('uncertain send')); await f.settle();
        await f.respond(1, code, htmlResponse());
        assert.equal(f.parses(), 3); assert.equal(f.nodes.history.textContent, ''); assert.equal(f.nodes.draft.value, '');
        assert.equal(f.timers.size, 0); assert.equal(f.root.replaceCount, 1); assert.equal(f.retry.hidden, true);
        assert.equal(f.requests.filter(r => r.options.method === 'POST').length, 1);
    } finally { f.restore(); }
});

for (const code of [401, 403, 404, 419]) test(`${code} aborts polling, removes private surfaces and leaves manual access only`, async () => {
    const f = fixture();
    try {
        await f.tick(); await f.respond(0, code);
        assert.deepEqual(f.root.children, [f.recovery]); assert.equal(f.recovery.hidden, false);
        for (const key of ['history', 'conversation-list', 'information-body', 'information', 'composer', 'upload-form']) assert.equal(f.nodes[key].textContent, '');
        assert.equal(f.nodes.draft.value, ''); assert.equal(f.token.value, ''); assert.equal(f.root.dataset.pollUrl, undefined);
        assert.equal(f.requests[0].options.signal.aborted, true); assert.equal(f.timers.size, 0);
        assert.equal(f.retry.hidden, true); assert.equal(f.access.hidden, false); assert.equal(f.title.focused, true); assert.equal(f.title['aria-level'], '1');
        f.focus(); f.retry.dispatchEvent(new Event('click')); f.submit(); await f.settle();
        assert.equal(f.requests.length, 1); assert.equal(f.timers.size, 0);
        assert.equal(f.header.textContent === '', code === 401 || code === 419);
    } finally { f.restore(); }
});

for (const code of [409, 429, 503]) test(`${code} pauses reads until a manual GET and resumes one normal timer`, async () => {
    const f = fixture();
    try {
        await f.tick(); await f.respond(0, code); f.focus();
        assert.equal(f.timers.size, 0); assert.equal(f.nodes.history.textContent, 'HISTORIAL PRIVADO');
        assert.equal(f.nodes.draft.value, 'BORRADOR PRIVADO'); assert.equal(f.retry.hidden, false);
        f.retry.dispatchEvent(new Event('click')); await f.settle();
        assert.equal(f.requests.length, 2); assert.equal(f.requests[1].options.method, undefined);
        await f.respond(1); assert.equal(f.recovery.hidden, true);
        assert.equal(f.timers.size, 1); assert.equal([...f.timers.values()][0].delay, 10000);
        assert.equal(f.requests.filter(x => x.options.method === 'POST').length, 0);
    } finally { f.restore(); }
});

test('lazy Messages mounts once after a delayed import and cleans in-flight work on Turbo departure', async () => {
    const f = fixture({ managed: true, deferredMount: true });
    try {
        await f.settle(); assert.equal(f.timers.size, 0);
        document.dispatchEvent(new Event('turbo:before-render'));
        f.setPageRoot(null); document.dispatchEvent(new Event('turbo:load'));
        await f.finishImport(); assert.equal(f.timers.size, 0); assert.equal(f.requests.length, 0);
        f.setPageRoot(f.root); document.dispatchEvent(new Event('turbo:load'));
        document.dispatchEvent(new Event('turbo:load')); assert.equal(f.timers.size, 1);
        await f.tick(); assert.equal(f.requests.length, 1);
        f.cleanup(); assert.equal(f.requests[0].options.signal.aborted, true);
        await f.respond(0); assert.equal(f.nodes.history.textContent, 'HISTORIAL PRIVADO');
        assert.equal(f.timers.size, 0);
    } finally { f.restore(); }
});

test('lazy Messages frozen before import completion cannot start polling on a stale BFCache page', async () => {
    const f = fixture({ managed: true, deferredMount: true });
    try {
        let reloads = 0; window.location.reload = () => reloads++;
        await f.settle();
        window.dispatchEvent(Object.assign(new Event('pagehide'), { persisted: true }));
        await f.finishImport(); assert.equal(f.timers.size, 0);
        window.dispatchEvent(Object.assign(new Event('pageshow'), { persisted: true }));
        assert.equal(reloads, 1); assert.equal(f.timers.size, 0); assert.equal(f.requests.length, 0);
    } finally { f.restore(); }
});

test('network failure pauses automatic retries and a malformed read never replaces safe content', async () => {
    const f = fixture();
    try {
        await f.tick(); f.requests[0].reject(new TypeError('offline')); await f.settle();
        f.focus(); assert.equal(f.timers.size, 0); assert.equal(f.nodes.history.textContent, 'HISTORIAL PRIVADO');
        f.retry.dispatchEvent(new Event('click')); await f.settle(); await f.respond(1, 200, { history_html: 'BAD', can_send: true });
        assert.equal(f.timers.size, 0); assert.equal(f.nodes.history.textContent, 'HISTORIAL PRIVADO');
    } finally { f.restore(); }
});

test('can_send=false keeps authorized history and normal polling without inferring a revocation', async () => {
    const f = fixture();
    try {
        await f.tick(); await f.respond(0, 200, { history_html: 'HISTORIAL PERMITIDO', inbox_html: 'ALIAS', information_html: 'INFO', can_send: false });
        assert.equal(f.nodes.history.textContent, 'HISTORIAL PERMITIDO'); assert.equal(f.nodes.draft.disabled, true);
        assert.equal(f.recovery.hidden, true); assert.equal(f.timers.size, 1); assert.equal(f.requests[0].options.signal.aborted, false);
    } finally { f.restore(); }
});

test('a lost send acknowledgement survives manual reading and retries exactly the same mutation once', async () => {
    const f = fixture();
    try {
        f.submit(); await f.settle(); f.requests[0].reject(new TypeError('offline')); await f.settle();
        assert.equal(f.timers.size, 0); assert.equal(f.nodes.draft.readOnly, true);
        f.retry.dispatchEvent(new Event('click')); await f.settle(); await f.respond(1);
        assert.equal(f.requests.filter(x => x.options.method === 'POST').length, 1);
        f.nodes.draft.value = 'CAMBIO NO ENVIABLE'; f.submit(); await f.settle();
        assert.deepEqual([...f.requests[2].options.body], [...f.requests[0].options.body]);
        await f.respond(2, 200, { id: 10, next_token: 'd5c101d0-2ae1-4321-9123-123456789013' });
        await f.respond(3); assert.equal(f.nodes.draft.value, ''); assert.equal(f.token.value, 'd5c101d0-2ae1-4321-9123-123456789013');
        assert.equal(f.requests.filter(x => x.options.method === 'POST').length, 2);
    } finally { f.restore(); }
});

test('definitive failure during sending ignores a late successful poll and purges the frozen attempt', async () => {
    const f = fixture();
    try {
        await f.tick(); f.submit(); await f.settle(); await f.respond(1, 401);
        await f.respond(0); assert.deepEqual(f.root.children, [f.recovery]); assert.equal(f.nodes.history.textContent, '');
        assert.equal(f.nodes.draft.value, ''); assert.equal(f.timers.size, 0); assert.equal(f.requests.length, 2);
    } finally { f.restore(); }
});

test('a definitive send failure purges immediately without waiting for an error body', async () => {
    const f = fixture();
    try {
        f.submit(); await f.settle();
        f.requests[0].resolve({ ok: false, status: 419, json: () => new Promise(() => {}) }); await f.settle();
        assert.deepEqual(f.root.children, [f.recovery]); assert.equal(f.nodes.draft.value, '');
        assert.equal(f.timers.size, 0); assert.equal(f.requests[0].options.signal.aborted, true);
    } finally { f.restore(); }
});

test('Turbo cleanup aborts in-flight reads and late results cannot repaint the detached private page', async () => {
    const f = fixture();
    try {
        await f.tick(); f.cleanup(); await f.respond(0);
        assert.equal(f.requests[0].options.signal.aborted, true); assert.equal(f.nodes.history.textContent, 'HISTORIAL PRIVADO');
        assert.equal(f.timers.size, 0);
    } finally { f.restore(); }
});

test('an older successful read cannot silently resume polling after an uncertain send', async () => {
    const f = fixture();
    try {
        await f.tick(); f.submit(); await f.settle();
        f.requests[1].reject(new TypeError('connection lost after sending')); await f.settle();
        await f.respond(0);
        assert.equal(f.recovery.hidden, false); assert.equal(f.timers.size, 0);
        assert.equal(f.nodes.history.textContent, 'HISTORIAL PRIVADO'); assert.equal(f.nodes.draft.readOnly, true);
        assert.equal(f.nodes['conversation-list'].textContent, 'ALIAS PRIVADO');
        assert.equal(f.nodes['information-body'].textContent, 'ARCHIVO PRIVADO');
        assert.equal(f.header.textContent, 'IDENTIDAD PRIVADA');
        assert.equal(f.requests.filter(x => x.options.method === 'POST').length, 1);
    } finally { f.restore(); }
});

for (const code of [401,403,404,419]) test(`P1 ${code}: definitive pending read purges after an uncertain send changes its generation`, async () => {
    const f = fixture();
    try {
        await f.tick();
        assert.equal(f.requests.length, 1); assert.equal(f.requests[0].options.method, undefined);
        let aborts = 0;
        f.requests[0].options.signal.addEventListener('abort', () => aborts++);
        f.submit(); await f.settle();
        f.requests[1].reject(new TypeError('connection lost after sending')); await f.settle();
        assert.equal(f.recovery.hidden, false); assert.equal(f.nodes.draft.readOnly, true);
        assert.equal(f.timers.size, 0); assert.equal(f.requests.length, 2);
        assert.equal(f.nodes.history.textContent, 'HISTORIAL PRIVADO');
        // An explicit retry can be in flight when the older read finally denies access.
        f.submit(); await f.settle(); assert.equal(f.requests.length, 3);
        await f.respond(0, code);
        for (const key of ['history', 'conversation-list', 'information-body', 'information', 'composer', 'upload-form']) assert.equal(f.nodes[key].textContent, '');
        assert.equal(f.nodes.draft.value, ''); assert.equal(f.token.value, '');
        assert.deepEqual(f.root.children, [f.recovery]); assert.equal(f.root.dataset.pollUrl, undefined);
        assert.equal(f.requests[2].options.signal.aborted, true); assert.equal(aborts, 1);
        assert.equal(f.header.textContent === '', code === 401 || code === 419);
        await f.respond(2, 200, { id: 41, next_token: 'd5c101d0-2ae1-4321-9123-123456789013' });
        f.focus(); f.retry.dispatchEvent(new Event('click')); f.submit(); await f.settle();
        assert.equal(f.nodes.history.textContent, ''); assert.equal(f.nodes.draft.value, '');
        assert.equal(f.root.replaceCount, 1); assert.equal(f.title.focusCount, 1);
        assert.equal(f.timers.size, 0); assert.equal(f.requests.length, 3);
        assert.equal(f.requests.filter(x => x.options.method === 'POST').length, 2);
    } finally { f.restore(); }
});

test('all definitive statuses from a Turbo-disposed controller are ignored', async () => {
    for (const code of [401,403,404,419]) {
        const f = fixture({ managed: true });
        try {
            await f.tick(); f.cleanup(); await f.respond(0, code);
            assert.equal(f.requests[0].options.signal.aborted, true);
            assert.equal(f.root.replaceCount || 0, 0); assert.equal(f.header.textContent, 'IDENTIDAD PRIVADA');
            assert.equal(f.timers.size, 0);
        } finally { f.restore(); }
    }
});

for (const change of ['conversation', 'identity']) test(`a late definitive error cannot revoke the new ${change} context`, async () => {
    const previous = fixture({ managed: true });
    let current;
    try {
        await previous.tick();
        previous.cleanup(); // The real onPage Turbo lifecycle disposes the old controller.
        current = fixture({ managed: true, pollUrl: change === 'conversation' ? '/mensajes/20/actualizaciones' : '/mensajes/10/actualizaciones' });
        current.nodes.history.textContent = 'HISTORIAL DEL NUEVO CONTEXTO';
        current.nodes.draft.value = 'BORRADOR DEL NUEVO CONTEXTO';
        current.header.textContent = 'IDENTIDAD ACTUAL';
        await previous.respond(0, change === 'conversation' ? 404 : 401);
        assert.equal(previous.requests[0].options.signal.aborted, true);
        assert.equal(current.nodes.history.textContent, 'HISTORIAL DEL NUEVO CONTEXTO');
        assert.equal(current.nodes.draft.value, 'BORRADOR DEL NUEVO CONTEXTO');
        assert.equal(current.header.textContent, 'IDENTIDAD ACTUAL');
        assert.equal(current.root.replaceCount || 0, 0); assert.equal(current.timers.size, 1);
        assert.equal(current.recovery.hidden, true);
    } finally { current?.restore(); previous.restore(); }
});

test('simultaneous definitive POST and GET failures revoke only once in either response order', async () => {
    for (const order of [[0,1], [1,0]]) {
        const f = fixture();
        try {
            await f.tick(); f.submit(); await f.settle();
            let aborts = 0;
            f.requests[0].options.signal.addEventListener('abort', () => aborts++);
            for (const index of order) f.requests[index].resolve({ ok: false, status: index === 0 ? 401 : 419, json: async () => ({}) });
            await f.settle();
            assert.equal(f.root.replaceCount, 1); assert.equal(f.title.focusCount, 1); assert.equal(aborts, 1);
            assert.equal(f.nodes.history.textContent, ''); assert.equal(f.nodes.draft.value, '');
            assert.equal(f.timers.size, 0); assert.equal(f.requests.length, 2);
        } finally { f.restore(); }
    }
});

test('a late temporary read failure after an uncertain send preserves authorized content and the pause', async () => {
    for (const failure of [503, 'network']) {
        const f = fixture();
        try {
            await f.tick(); f.submit(); await f.settle();
            f.requests[1].reject(new TypeError('uncertain send')); await f.settle();
            if (failure === 503) await f.respond(0, 503);
            else { f.requests[0].reject(new TypeError('offline read')); await f.settle(); }
            assert.equal(f.nodes.history.textContent, 'HISTORIAL PRIVADO'); assert.equal(f.nodes.draft.value, 'BORRADOR PRIVADO');
            assert.equal(f.header.textContent, 'IDENTIDAD PRIVADA'); assert.equal(f.nodes.draft.readOnly, true);
            assert.equal(f.recovery.hidden, false); assert.equal(f.timers.size, 0); assert.equal(f.requests.length, 2);
        } finally { f.restore(); }
    }
});

test('pause during an in-flight poll cannot reschedule, and stop cannot be resumed', async () => {
    const tasks = new Map(); let id = 0, finish;
    const poll = createPoller({ run: () => new Promise(resolve => { finish = resolve; }), active: () => true,
        schedule: (fn, delay) => { tasks.set(++id, { fn, delay }); return id; }, cancel: key => tasks.delete(key) });
    poll.start(); const task = tasks.get(1); tasks.delete(1); const running = task.fn();
    poll.pause(); poll.wake(); finish(); await running; assert.equal(tasks.size, 0);
    poll.resume(); poll.resume(); assert.equal(tasks.size, 1); assert.equal([...tasks.values()][0].delay, 10000);
    poll.stop(); poll.resume(); assert.equal(tasks.size, 0);
});
