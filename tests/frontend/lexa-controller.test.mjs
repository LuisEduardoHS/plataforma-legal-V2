import test from 'node:test';
import assert from 'node:assert/strict';

globalThis.document = Object.assign(new EventTarget(), {
    querySelector: () => null,
    body: { style: { overflow: '' } },
});
globalThis.window = Object.assign(new EventTarget(), {
    location: { origin: 'http://localhost', href: 'http://localhost/lexa' },
    innerHeight: 800,
});
globalThis.requestAnimationFrame = (fn) => { fn(); return 1; };

const { setupLexa } = await import('../../resources/js/lexa.js');

class MockNode extends EventTarget {
    constructor(tag = 'div', text = '') {
        super();
        this.tagName = tag.toUpperCase();
        this.textContent = text;
        this.value = text;
        this.hidden = false;
        this.disabled = false;
        this.readOnly = false;
        this.open = false;
        this.rows = 3;
        this.scrollTop = 0;
        this.scrollHeight = 120;
        this.dataset = {};
        this.style = { overflow: '', height: '', setProperty() {} };
        this.attrs = new Map();
        this.children = [];
        this.nodes = {};
        this.classes = new Set();
        this.classList = {
            add: (...c) => c.forEach((x) => this.classes.add(x)),
            remove: (...c) => c.forEach((x) => this.classes.delete(x)),
            toggle: (c, force) => {
                const add = force === undefined ? !this.classes.has(c) : Boolean(force);
                if (add) this.classes.add(c);
                else this.classes.delete(c);
            },
            contains: (c) => this.classes.has(c),
        };
    }
    setAttribute(name, val) { this.attrs.set(name, String(val)); }
    getAttribute(name) { return this.attrs.get(name) ?? null; }
    append(...items) { items.forEach((i) => { if (!this.children.includes(i)) this.children.push(i); }); }
    appendChild(item) { this.append(item); return item; }
    prepend(...items) { this.children.unshift(...items); }
    replaceChildren(...items) { this.children = [...items]; }
    remove() {}
    focus() { this.focused = true; }
    scrollIntoView() {}
    showModal() { this.open = true; }
    close() { this.open = false; this.dispatchEvent(new Event('close')); }
    querySelector(sel) { return this.nodes[sel] || null; }
}

function createLexaFixture({ generationEnabled = true } = {}) {
    const keys = ['document', 'window', 'fetch', 'matchMedia', 'requestAnimationFrame'];
    const previous = Object.fromEntries(keys.map((k) => [k, globalThis[k]]));

    const root = new MockNode('section');
    const names = [
        'sidebar', 'history-dialog', 'delete-dialog', 'prompt', 'consultation-mode',
        'thread', 'thread-list', 'lexa-banner', 'lexa-banner-text', 'lexa-banner-retry',
        'lexa-banner-reload', 'lexa-banner-login', 'delete-session', 'load-more-sessions',
        'load-more-messages', 'lexa-status', 'history-list', 'search', 'history-empty',
        'send', 'context-details', 'context-title', 'context-mode', 'context-count',
        'context-files', 'context-empty', 'file-error', 'welcome', 'suggestions',
        'session-title', 'composer', 'legal-state', 'toolbar-mode',
    ];
    const nodes = Object.fromEntries(names.map((n) => [n, new MockNode()]));
    for (const name of names) {
        root.nodes[`[data-${name}]`] = nodes[name];
    }
    const layout = new MockNode();
    root.nodes['.lexa-layout'] = layout;

    const payload = {
        current_user: { id: 1, name: 'Ana' },
        generation_enabled: generationEnabled,
        modes: [
            { id: 'orientation', label: 'Orientación' },
            { id: 'contract', label: 'Revisar un contrato' },
        ],
        sessions: [
            { id: '101', title: 'Consulta A', mode: 'orientation', status: 'active' },
            { id: '102', title: 'Consulta B', mode: 'contract', status: 'active' },
        ],
        next_cursor: null,
        endpoints: { conversations: '/lexa/conversaciones', login: '/iniciar-sesion' },
    };
    root.nodes['[data-lexa-data]'] = new MockNode('script', JSON.stringify(payload));

    root.nodes['[data-session-template]'] = {
        content: {
            firstElementChild: {
                cloneNode() {
                    const btn = new MockNode('button');
                    const span = new MockNode('span');
                    btn.nodes.span = span;
                    btn.matches = (sel) => sel === '[data-session-id]';
                    btn.closest = (sel) => (sel === 'button' ? btn : null);
                    return btn;
                },
            },
        },
    };
    root.nodes['[data-message-template]'] = {
        content: {
            firstElementChild: {
                cloneNode() {
                    const art = new MockNode('article');
                    art.nodes['[data-message-author]'] = new MockNode('strong');
                    art.nodes['[data-copy-message]'] = new MockNode('button');
                    art.nodes['[data-message-text]'] = new MockNode('div');
                    return art;
                },
            },
        },
    };

    const requests = [];
    globalThis.fetch = (url, options = {}) => new Promise((resolve, reject) => {
        requests.push({ url, options, resolve, reject });
    });
    globalThis.matchMedia = () => Object.assign(new EventTarget(), { matches: true });
    globalThis.requestAnimationFrame = (fn) => { fn(); return 1; };

    const doc = Object.assign(new EventTarget(), {
        body: { style: { overflow: '' } },
        querySelector: (sel) => (sel === 'meta[name="csrf-token"]' ? { getAttribute: () => 'csrf-123' } : null),
        createElement: (tag) => new MockNode(tag),
        createTextNode: (text) => new MockNode('text', text),
        createDocumentFragment: () => new MockNode('fragment'),
    });
    const win = Object.assign(new EventTarget(), {
        location: { origin: 'http://localhost', href: 'http://localhost/lexa' },
        innerHeight: 800,
    });
    globalThis.document = doc;
    globalThis.window = win;

    const controller = new AbortController();
    const cleanups = [];
    const beforeVisits = [];

    setupLexa(root, {
        listen(target, type, cb) { target?.addEventListener(type, cb, { signal: controller.signal }); },
        onCleanup(cb) { cleanups.push(cb); },
        onBeforeVisit(cb) { beforeVisits.push(cb); },
    });

    const clickSession = (id) => {
        const btn = nodes['history-list'].children.find((b) => b.dataset.sessionId === String(id));
        const ev = new Event('click', { bubbles: true });
        Object.defineProperty(ev, 'target', { value: btn });
        root.dispatchEvent(ev);
    };

    const clickNewSession = () => {
        const btn = new MockNode('button');
        btn.matches = (sel) => sel === '[data-new-session]';
        btn.closest = () => btn;
        const ev = new Event('click', { bubbles: true });
        Object.defineProperty(ev, 'target', { value: btn });
        root.dispatchEvent(ev);
    };

    const settle = () => new Promise((r) => setImmediate(r));

    return {
        root, nodes, requests, settle, clickSession, clickNewSession,
        beforeVisit: () => beforeVisits.forEach((fn) => fn()),
        cleanup: () => { controller.abort(); cleanups.forEach((fn) => fn()); },
        restore: () => {
            controller.abort();
            cleanups.forEach((fn) => fn());
            for (const k of keys) globalThis[k] = previous[k];
        },
    };
}

test('switching LEXA sessions aborts stale thread request and preserves independent drafts', async () => {
    const f = createLexaFixture();
    try {
        // Type a draft on welcome screen
        f.nodes.prompt.value = 'Borrador inicial';
        f.nodes.prompt.dispatchEvent(new Event('input'));

        // Click Session 101 (starts GET /lexa/conversaciones/101/mensajes)
        f.clickSession('101');
        await f.settle();
        assert.equal(f.requests.length, 1);
        assert.equal(f.requests[0].options.signal.aborted, false);

        // Immediately switch to Nueva consulta before 101 finishes loading
        f.clickNewSession();
        assert.equal(f.requests[0].options.signal.aborted, true, 'stale thread request must be aborted when leaving session');

        // Late failure on 101 must not display an error banner on Nueva consulta
        f.requests[0].resolve({ ok: false, status: 500, json: async () => ({ message: 'Error 101' }) });
        await f.settle();
        assert.equal(f.nodes['lexa-banner'].hidden, true, 'stale session error must not pollute active view');
    } finally {
        f.restore();
    }
});

test('LEXA generation on session A allows switching to session B without locking B composer or losing drafts', async () => {
    const f = createLexaFixture({ generationEnabled: true });
    try {
        // Select Session 101 and finish loading its thread
        f.clickSession('101');
        await f.settle();
        f.requests[0].resolve({
            ok: true,
            status: 200,
            json: async () => ({ messages: [{ id: 1, role: 'user', content: 'Hola' }], next_cursor: null }),
        });
        await f.settle();

        // Also preload Session 102 and give it a draft
        f.clickSession('102');
        await f.settle();
        f.requests[1].resolve({
            ok: true,
            status: 200,
            json: async () => ({ messages: [], next_cursor: null }),
        });
        await f.settle();
        f.nodes.prompt.value = 'Borrador conservado en 102';
        f.nodes.prompt.dispatchEvent(new Event('input'));

        // Switch back to 101 and submit a new message (triggers POST mensaje + POST generar)
        f.clickSession('101');
        await f.settle();
        f.nodes.prompt.value = 'Consulta en 101';
        f.nodes.prompt.dispatchEvent(new Event('input'));
        f.nodes.composer.dispatchEvent(new Event('submit', { cancelable: true }));
        await f.settle();

        // Resolve POST /lexa/conversaciones/101/mensajes
        assert.equal(f.requests.length, 3);
        f.requests[2].resolve({
            ok: true,
            status: 201,
            json: async () => ({ message: { id: 2, role: 'user', content: 'Consulta en 101' } }),
        });
        await f.settle();

        // Now POST /lexa/conversaciones/101/generar is in flight!
        assert.equal(f.requests.length, 4);
        assert.equal(f.nodes.prompt.readOnly, true, 'session 101 composer is readOnly while generating');
        assert.equal(f.nodes['lexa-banner'].hidden, false);
        assert.equal(f.nodes['lexa-banner'].classList.contains('is-generating'), true);

        // User switches to Session 102 while 101 is still generating
        f.clickSession('102');
        await f.settle();
        assert.equal(f.nodes.prompt.value, 'Borrador conservado en 102', 'session 102 draft must be preserved');
        assert.equal(f.nodes.prompt.readOnly, false, 'session 102 composer must remain editable');
        assert.equal(f.nodes['lexa-banner'].hidden, true, 'session 101 generating banner must not stay on session 102');

        // Session 101 generation completes in background
        f.requests[3].resolve({
            ok: true,
            status: 201,
            json: async () => ({
                conversation: { id: '101', title: 'Consulta A actualizada', status: 'active' },
                message: { id: 3, role: 'assistant', content: 'Respuesta IA para 101' },
            }),
        });
        await f.settle();

        // Session 102 is still active and undisturbed
        assert.equal(f.nodes.prompt.value, 'Borrador conservado en 102');

        // Switching back to 101 shows the completed response and unlocked composer
        f.clickSession('101');
        await f.settle();
        assert.equal(f.nodes.prompt.readOnly, false);
        assert.equal(f.nodes['thread-list'].children.length, 3);
    } finally {
        f.restore();
    }
});
