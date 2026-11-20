import test from 'node:test';
import assert from 'node:assert/strict';
import { loadOnPage, onPage } from '../../resources/js/ui/page.js';

const settle = () => new Promise(resolve => setImmediate(resolve));
const deferred = () => { let resolve; const promise = new Promise(done => { resolve = done; }); return { promise, resolve }; };
function fixture() {
    const previous = { document: globalThis.document, window: globalThis.window };
    const roots = new Map();
    const doc = Object.assign(new EventTarget(), { body: { style: { overflow: '' } }, querySelector: selector => roots.get(selector) || null });
    let reloads = 0;
    const win = Object.assign(new EventTarget(), { location: { reload() { reloads++; } } });
    globalThis.document = doc; globalThis.window = win;
    const event = (type, persisted) => Object.assign(new Event(type), { persisted });
    return {
        roots, doc, win, reloads: () => reloads,
        visit(selector, root = new EventTarget()) {
            doc.dispatchEvent(new Event('turbo:before-render'));
            roots.clear(); if (selector) roots.set(selector, root);
            doc.body = { style: { overflow: '' } };
            doc.dispatchEvent(new Event('turbo:load'));
            return root;
        },
        hide(persisted) { win.dispatchEvent(event('pagehide', persisted)); },
        show() { win.dispatchEvent(event('pageshow', true)); },
        restore() { doc.dispatchEvent(new Event('turbo:before-render')); Object.assign(globalThis, previous); },
    };
}

test('loads only matching modules, shares in-flight work and mounts once on each Turbo return', async () => {
    const f = fixture(), ready = deferred();
    try {
        let imports = 0, foreignImports = 0, mounts = 0, releases = 0, events = 0;
        loadOnPage('[page]', async () => {
            imports++; await ready.promise;
            onPage('[page]', (_, { listen, onCleanup }) => {
                mounts++; listen(f.win, 'resize', () => events++); onCleanup(() => releases++);
            });
        });
        loadOnPage('[foreign]', () => { foreignImports++; });
        await settle(); assert.equal(imports, 0);
        f.visit('[page]'); f.doc.dispatchEvent(new Event('turbo:load'));
        await settle(); assert.equal(imports, 1); assert.equal(mounts, 0);
        ready.resolve(); await settle();
        f.doc.dispatchEvent(new Event('turbo:load')); assert.equal(mounts, 1);
        f.win.dispatchEvent(new Event('resize')); assert.equal(events, 1);
        f.visit(null); f.win.dispatchEvent(new Event('resize')); assert.equal(events, 1);
        f.visit('[page]'); await settle(); assert.equal(mounts, 2);
        assert.equal(imports, 1); assert.equal(foreignImports, 0); assert.equal(releases, 1);
    } finally { f.restore(); }
});

test('late module registration between before-render and load cannot mount the outgoing body', async () => {
    const f = fixture(), ready = deferred(), mounted = [];
    try {
        const old = f.visit('[page]');
        loadOnPage('[page]', async () => { await ready.promise; onPage('[page]', root => mounted.push(root)); });
        await settle(); f.doc.dispatchEvent(new Event('turbo:before-render'));
        ready.resolve(); await settle(); assert.deepEqual(mounted, []);
        const current = new EventTarget(); f.roots.set('[page]', current);
        f.doc.dispatchEvent(new Event('turbo:load'));
        assert.deepEqual(mounted, [current]); assert.notEqual(current, old);
    } finally { f.restore(); }
});

test('imports finishing on an unrelated page stay dormant until their own page returns', async () => {
    const f = fixture(), ready = deferred();
    try {
        let mounts = 0;
        f.visit('[page]');
        loadOnPage('[page]', async () => { await ready.promise; onPage('[page]', () => mounts++); });
        await settle(); f.visit('[other]'); ready.resolve(); await settle(); assert.equal(mounts, 0);
        f.visit('[page]'); assert.equal(mounts, 1);
    } finally { f.restore(); }
});

test('BFCache keeps a ready controller without duplicating listeners or losing its dialog state', async () => {
    const f = fixture();
    try {
        let mounts = 0, events = 0, cleanups = 0;
        f.visit('[page]');
        loadOnPage('[page]', async () => onPage('[page]', (_, { listen, onCleanup }) => {
            mounts++; f.doc.body.style.overflow = 'hidden';
            listen(f.win, 'resize', () => events++); onCleanup(() => cleanups++);
        }));
        await settle(); f.hide(true); f.show();
        assert.equal(mounts, 1); assert.equal(cleanups, 0); assert.equal(f.doc.body.style.overflow, 'hidden');
        f.win.dispatchEvent(new Event('resize')); assert.equal(events, 1); assert.equal(f.reloads(), 0);
        f.hide(false); f.hide(false); assert.equal(cleanups, 1);
        assert.equal(f.doc.body.style.overflow, '');
    } finally { f.restore(); }
});

test('BFCache while an import is pending never mounts stale content and reloads on restoration', async () => {
    const f = fixture(), ready = deferred();
    try {
        let mounts = 0;
        f.visit('[page]');
        loadOnPage('[page]', async () => { await ready.promise; onPage('[page]', () => mounts++); });
        await settle(); f.hide(true); ready.resolve(); await settle();
        assert.equal(mounts, 0); f.show(); assert.equal(mounts, 0); assert.equal(f.reloads(), 1);
    } finally { f.restore(); }
});

test('a failed import is handled and retries only on a later visit', async () => {
    const f = fixture(), previousError = console.error;
    let errors = 0; console.error = () => errors++;
    try {
        let imports = 0, mounts = 0;
        f.visit('[page]');
        loadOnPage('[page]', async () => { if (++imports === 1) throw new Error('offline'); onPage('[page]', () => mounts++); });
        await settle(); assert.equal(errors, 1); assert.equal(mounts, 0); assert.equal(imports, 1);
        f.visit('[page]'); await settle(); assert.equal(imports, 2); assert.equal(mounts, 1);
    } finally { console.error = previousError; f.restore(); }
});

test('one failing cleanup cannot leave other controllers or global listeners active', () => {
    const f = fixture(), previousError = console.error;
    let errors = 0; console.error = () => errors++;
    try {
        let events = 0, releases = 0;
        f.visit('[page]');
        onPage('[page]', (_, { onCleanup }) => onCleanup(() => { throw new Error('cleanup'); }));
        onPage('[page]', (_, { listen, onCleanup }) => {
            listen(f.win, 'resize', () => events++); onCleanup(() => releases++);
        });
        f.visit(null); f.win.dispatchEvent(new Event('resize'));
        assert.equal(errors, 1); assert.equal(releases, 1); assert.equal(events, 0);
    } finally { f.restore(); console.error = previousError; }
});

test('shared public/app registrations coalesce while distinct controllers on one root remain independent', () => {
    const f = fixture();
    try {
        let sharedMounts = 0, otherMounts = 0, events = 0;
        f.visit('[page]');
        const shared = (_, { listen }) => { sharedMounts++; listen(f.win, 'resize', () => events++); };
        onPage('[page]', shared); onPage('[page]', shared);
        onPage('[page]', () => otherMounts++);
        f.win.dispatchEvent(new Event('resize'));
        assert.equal(sharedMounts, 1); assert.equal(otherMounts, 1); assert.equal(events, 1);
        f.visit('[page]'); assert.equal(sharedMounts, 2); assert.equal(otherMounts, 2);
    } finally { f.restore(); }
});
