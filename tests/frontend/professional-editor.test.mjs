import test from 'node:test';
import assert from 'node:assert/strict';
import { setupProfessionalEditor } from '../../resources/js/profiles/professional.js';

function harness({ native = true, response = { categories: ['Descripción profesional'], pending_change: 'opaque-proposal', notice: 'Se invalidará la verificación vigente.' }, status = 200 } = {}) {
    const previous = Object.fromEntries(['document', 'window', 'fetch', 'FormData'].map(key => [key, globalThis[key]]));
    const document = { body: { style: { overflow: 'auto' } }, createElement: () => ({ textContent: '' }) };
    globalThis.document = document;
    const navigations = [], requests = [], cleanups = [];
    globalThis.window = { location: { assign: value => navigations.push(value) } };
    globalThis.FormData = class {
        constructor() { this.entries = [['_token', 'synthetic-csrf'], ['description', 'private-draft']]; }
        append(key, value) { this.entries.push([key, value]); }
    };
    globalThis.fetch = async (url, options) => { requests.push({ url, options }); return { ok: status < 400, status, json: async () => response }; };
    const element = () => {
        const item = new EventTarget();
        item.attributes = {}; item.setAttribute = (key, value) => { item.attributes[key] = value; };
        item.focus = () => { document.activeElement = item; }; item.isConnected = true;
        item.click = () => item.dispatchEvent(new Event('click', { cancelable: true }));
        return item;
    };
    const form = element(), final = element(), dialog = element(), trigger = element(), cancel = element();
    const apply = element(), preserve = element(), finalButton = element(), feedback = element();
    apply.name = preserve.name = 'update_specialties'; apply.value = '1'; preserve.value = '0';
    const pending = { value: '' }, notice = { textContent: '' }, list = { items: [], replaceChildren(...items) { this.items = items; } };
    form.action = '/perfil/profesional/revisar'; form.querySelector = () => feedback; form.querySelectorAll = () => [apply, preserve];
    final.querySelector = selector => selector === 'button' ? finalButton : pending;
    dialog.id = 'material'; dialog.open = false;
    if (native) dialog.showModal = () => { dialog.open = true; };
    dialog.close = () => { if (dialog.open) { dialog.open = false; dialog.dispatchEvent(new Event('close')); } };
    dialog.querySelector = () => cancel; dialog.querySelectorAll = () => [cancel];
    const confirmation = { hidden: true, querySelector: selector => ({ dialog, '[data-professional-final]': final, '[data-dialog-open]': trigger, '[data-professional-categories]': list, '[data-professional-description]': notice })[selector] };
    const root = { querySelector: selector => selector === '[data-professional-edit]' ? form : confirmation };
    const controller = new AbortController();
    const lifecycle = { listen: (target, type, handler) => target.addEventListener(type, handler, { signal: controller.signal }), onCleanup: callback => cleanups.push(callback) };
    const submit = (target = form, submitter = apply) => {
        const event = new Event('submit', { cancelable: true });
        Object.defineProperty(event, 'submitter', { value: submitter });
        target.dispatchEvent(event); return event;
    };
    setupProfessionalEditor(root, lifecycle);
    return { form, final, dialog, trigger, cancel, apply, preserve, finalButton, feedback, pending, list, notice, confirmation, requests, navigations, submit, document,
        cleanup() { controller.abort(); cleanups.forEach(callback => callback()); },
        restore() { controller.abort(); Object.assign(globalThis, previous); } };
}
const settle = () => new Promise(resolve => setImmediate(resolve));

test('server categories open the native dialog without a PUT and Cancel returns focus to the submitter', async () => {
    const h = harness();
    try {
        assert.equal(h.submit().defaultPrevented, true); await settle();
        assert.equal(h.requests.length, 1); assert.equal(h.requests[0].options.method, 'POST');
        assert.equal(h.dialog.open, true); assert.equal(h.document.activeElement, h.cancel);
        assert.deepEqual(h.list.items.map(item => item.textContent), ['Descripción profesional']);
        assert.equal(h.notice.textContent, 'Se invalidará la verificación vigente.');
        assert.equal(h.pending.value, 'opaque-proposal'); h.cancel.click();
        assert.equal(h.dialog.open, false); assert.equal(h.document.activeElement, h.apply);
        assert.equal(h.document.body.style.overflow, 'auto'); assert.equal(h.requests.length, 1);
    } finally { h.restore(); }
});

test('preserve and apply submitters send their explicit intent and native final PUT can submit only once', async () => {
    const h = harness();
    try {
        h.submit(h.form, h.preserve); await settle();
        assert.deepEqual(h.requests[0].options.body.entries.at(-1), ['update_specialties', '0']);
        assert.equal(h.requests[0].options.credentials, 'same-origin');
        assert.equal(h.submit(h.final).defaultPrevented, false);
        assert.equal(h.finalButton.disabled, true); assert.equal(h.submit(h.final).defaultPrevented, true);
        assert.equal(h.requests.length, 1);
    } finally { h.restore(); }
});

test('double clicks coalesce and an input edit invalidates the pending client proposal', async () => {
    const h = harness();
    try {
        h.submit(); h.submit(); await settle(); assert.equal(h.requests.length, 1);
        assert.deepEqual(h.requests[0].options.body.entries.at(-1), ['update_specialties', '1']);
        h.dialog.close(); h.form.dispatchEvent(new Event('input'));
        assert.equal(h.pending.value, ''); assert.equal(h.confirmation.hidden, true);
    } finally { h.restore(); }
});

test('no material change uses the fixed server redirect without opening a confirmation', async () => {
    const h = harness({ response: { redirect: '/perfil' } });
    try {
        h.submit(); await settle(); assert.deepEqual(h.navigations, ['/perfil']);
        assert.equal(h.dialog.open, false); assert.equal(h.requests.length, 1);
    } finally { h.restore(); }
});

test('unsupported dialog keeps the native POST fallback and municipality blocking prevents review', async () => {
    let h = harness({ native: false });
    try { assert.equal(h.submit().defaultPrevented, false); assert.equal(h.requests.length, 0); } finally { h.restore(); }
    h = harness();
    try {
        // A previously mounted location listener cancels before this listener; dispatch a cancelled event.
        const event = new Event('submit', { cancelable: true }); event.preventDefault(); h.form.dispatchEvent(event);
        await settle(); assert.equal(h.requests.length, 0);
    } finally { h.restore(); }
});

test('server validation is text, conflict is recoverable and no request is retried automatically', async () => {
    const h = harness({ status: 422, response: { errors: { headline: ['<script>never execute</script>'] } } });
    try {
        h.submit(); await settle(); assert.equal(h.feedback.textContent, '<script>never execute</script>');
        assert.equal(h.document.activeElement, h.feedback); assert.equal(h.dialog.open, false); assert.equal(h.requests.length, 1);
    } finally { h.restore(); }
});

test('Turbo cleanup aborts pending review, clears the proposal and releases scroll without restoring detached focus', async () => {
    const h = harness();
    try {
        h.submit(); await settle(); h.cleanup();
        assert.equal(h.dialog.open, false); assert.equal(h.pending.value, '');
        assert.equal(h.document.body.style.overflow, 'auto');
        assert.equal(h.requests[0].options.signal.aborted, true);
        assert.equal(h.submit().defaultPrevented, false);
    } finally { h.restore(); }
});

test('a stale version reports recovery guidance without offering a confirmation or retry', async () => {
    const h = harness({ status: 409, response: { message: 'Version conflict' } });
    try {
        h.submit(); await settle();
        assert.match(h.feedback.textContent, /cambiaron/); assert.equal(h.dialog.open, false);
        assert.equal(h.pending.value, ''); assert.equal(h.requests.length, 1);
    } finally { h.restore(); }
});

test('an unreadable response never claims success or automatically repeats a possibly completed write', async () => {
    const h = harness();
    try {
        globalThis.fetch = async () => ({ ok: false, status: 503, json: async () => { throw new Error('Not JSON'); } });
        h.submit(); await settle();
        assert.match(h.feedback.textContent, /no reintentaremos automáticamente/);
        assert.equal(h.dialog.open, false); assert.deepEqual(h.navigations, []);
    } finally { h.restore(); }
});

test('a late reply after Turbo disposal cannot restore a proposal or navigate the new page', async () => {
    const h = harness();
    try {
        let reply;
        globalThis.fetch = () => new Promise(resolve => { reply = resolve; });
        h.submit(); h.cleanup();
        reply({ ok: true, status: 200, json: async () => ({ pending_change: 'old-proposal', categories: ['Descripción'] }) });
        await settle(); assert.equal(h.dialog.open, false); assert.equal(h.pending.value, '');
        assert.deepEqual(h.navigations, []);
    } finally { h.restore(); }
});
