import test from 'node:test';
import assert from 'node:assert/strict';
import { setupProfileLocation } from '../../resources/js/profiles/location.js';
import { onPage } from '../../resources/js/ui/page.js';

class Control extends EventTarget {
    constructor(value = '') {
        super(); this.value = value; this.disabled = false; this.textContent = ''; this.attributes = {};
    }
    setAttribute(key, value) { this.attributes[key] = value; }
    focus() { this.focused = true; }
}
function fixture() {
    const previous = { document: globalThis.document, window: globalThis.window, fetch: globalThis.fetch };
    const document = new EventTarget();
    document.createElement = () => new Control();
    document.body = { style: { overflow: '' } };
    globalThis.document = document;
    globalThis.window = new EventTarget();
    window.location = new URL('http://localhost/perfil/editar');
    const root = { dataset: { municipalitiesUrl: '/perfil/editar?irrelevant=must-not-leak', locationCatalogState: '1' }, ownerDocument: document };
    const state = new Control('1');
    const city = new Control('11');
    city.ownerDocument = document;
    city.options = [{ value: '', textContent: 'Sin ubicación' }, { value: '11', textContent: 'Municipio A' }];
    city.replaceChildren = (...options) => { city.options = options; city.value = ''; };
    const form = new Control(), profile = new Control(), load = new Control(), status = new Control();
    const save = new Control();
    const fallback = { hidden: false }, ack = { required: true, disabled: false };
    const nodes = {
        '[data-location-state]': state, '[data-location-municipality]': city, '[data-location-form]': form,
        '[data-profile-edit-form]': profile, '[data-location-load]': load, '[data-location-status]': status,
        '[data-location-fallback]': fallback, '[data-location-ack]': ack,
    };
    root.querySelector = selector => nodes[selector];
    profile.querySelectorAll = () => [save];
    document.querySelector = () => root;
    // These are deliberately live, nonempty controls outside the catalog contract.
    const draft = {
        biography: { value: 'Biografía todavía sin guardar' }, username: { value: 'alias_borrador' },
        description: { value: 'Presentación extensa' }, phone: { value: '+52 555 123 4567' },
        license: { value: '1234567' }, contact: { checked: true }, specialty: { checked: true },
        intention: { checked: false }, visibility: { value: 'private' },
        photo: { files: Object.freeze([{ name: 'synthetic.png', size: 12 }]) },
    };
    const draftBefore = structuredClone(draft);
    const filesBefore = draft.photo.files;
    const requests = [];
    globalThis.fetch = (url, options) => new Promise((resolve, reject) => requests.push({ url, options, resolve, reject }));
    const controller = new AbortController();
    const cleanups = [];
    const context = {
        listen(target, name, callback) { target.addEventListener(name, callback, { signal: controller.signal }); },
        onCleanup(callback) { cleanups.push(callback); },
    };
    let disposed = false;
    const dispose = () => { if (!disposed) { disposed = true; controller.abort(); cleanups.forEach(fn => fn()); } };
    const change = value => { state.value = value; state.dispatchEvent(new Event('change')); };
    const submit = target => { const event = new Event('submit', { cancelable: true }); target.dispatchEvent(event); return event; };
    const settle = () => new Promise(resolve => setImmediate(resolve));
    const respond = async (index, id, items, ok = true) => {
        requests[index].resolve({ ok, json: async () => ({ state_id: Number(id), municipalities: items }) });
        await settle();
    };
    const unchanged = () => { assert.deepEqual(draft, draftBefore); assert.equal(draft.photo.files, filesBefore); };
    return {
        root, document, state, city, form, profile, load, save, status, fallback, ack, requests,
        change, submit, settle, respond, unchanged, dispose,
        mount() { setupProfileLocation(root, context); },
        restore() { dispose(); Object.assign(globalThis, previous); },
    };
}
function scenario(name, callback) {
    test(name, async () => { const f = fixture(); try { f.mount(); await callback(f); } finally { f.restore(); } });
}

scenario('personal and professional live drafts, specialty intention and file selection survive catalog loading', async f => {
    f.change('2');
    assert.equal(f.city.disabled, true);
    assert.equal(f.save.disabled, true);
    assert.match(f.status.textContent, /Cargando/);
    f.unchanged();
    await f.respond(0, 2, [{ id: 22, name: 'Municipio B' }]);
    f.unchanged();
    assert.equal(f.city.value, '', 'incompatible previous municipality is cleared');
    assert.equal(f.city.options[1].value, '22');
    assert.equal(f.save.disabled, false);
    assert.equal(f.city.disabled, false);
    assert.equal(f.ack.required, false);
    assert.equal(f.ack.disabled, true);
    assert.equal(f.fallback.hidden, true);
});

scenario('catalog requests contain only the state identifier and never a form body or private draft', async f => {
    f.change('2');
    const { url, options } = f.requests[0];
    assert.equal(String(url), 'http://localhost/perfil/editar?state_id=2');
    assert.equal(options.method, 'GET');
    assert.equal(options.body, undefined);
    assert.equal(options.headers.Accept, 'application/json');
    assert.equal(options.credentials, 'same-origin');
    assert.equal(options.cache, 'no-store');
    await f.respond(0, 2, [{ id: 22, name: '<script>public name</script>' }]);
    assert.equal(f.city.options[1].textContent, '<script>public name</script>');
    f.unchanged();
});

scenario('empty catalogs and clearing the state allow an explicit save without location', async f => {
    f.change('3');
    await f.respond(0, 3, []);
    assert.equal(f.city.options.length, 1);
    assert.match(f.status.textContent, /no tiene municipios/);
    assert.equal(f.save.disabled, false);
    f.change('');
    assert.equal(f.requests.length, 1);
    assert.equal(f.city.value, '');
    assert.match(f.status.textContent, /Sin estado/);
    assert.equal(f.submit(f.profile).defaultPrevented, false);
});

scenario('rapid changes abort old requests and ignore late responses even when transport ignores abort', async f => {
    f.change('2');
    f.change('3');
    assert.equal(f.requests[0].options.signal.aborted, true);
    await f.respond(1, 3, [{ id: 33, name: 'Actual' }]);
    f.city.value = '33';
    await f.respond(0, 2, [{ id: 22, name: 'Obsoleto' }]);
    assert.equal(f.city.value, '33');
    assert.equal(f.city.options[1].textContent, 'Actual');
    f.unchanged();
});

scenario('pending submits deduplicate and loaded catalogs reuse public in-memory options', async f => {
    f.change('2');
    assert.equal(f.submit(f.form).defaultPrevented, true);
    f.change('2');
    assert.equal(f.requests.length, 1);
    await f.respond(0, 2, [{ id: 22, name: 'B' }]);
    f.city.value = '22';
    f.submit(f.form);
    assert.equal(f.city.value, '22');
    f.change('1');
    f.change('2');
    assert.equal(f.requests.length, 1);
    assert.equal(f.city.options[1].value, '22');
});

scenario('HTTP failure blocks an incomplete save and retry recovers without touching draft fields', async f => {
    f.change('2');
    await f.respond(0, 2, [], false);
    assert.match(f.status.textContent, /No se pudieron/);
    assert.equal(f.load.textContent, 'Reintentar carga');
    assert.equal(f.save.disabled, true);
    assert.equal(f.submit(f.profile).defaultPrevented, true);
    assert.equal(f.load.focused, true);
    f.submit(f.form);
    assert.equal(f.requests.length, 2);
    await f.respond(1, 2, [{ id: 22, name: 'Recuperado' }]);
    assert.equal(f.submit(f.profile).defaultPrevented, false);
    assert.equal(f.save.disabled, false);
    f.unchanged();
});

scenario('disconnection preserves the draft and permits retry', async f => {
    f.change('2');
    f.requests[0].reject(new TypeError('Network disconnected'));
    await f.settle();
    assert.equal(f.load.textContent, 'Reintentar carga');
    f.unchanged();
    f.submit(f.form);
    await f.respond(1, 2, []);
    assert.equal(f.save.disabled, false);
});

scenario('a mismatched catalog response is rejected instead of silently replacing the latest state', async f => {
    f.change('2');
    await f.respond(0, 3, [{ id: 33, name: 'Otro estado' }]);
    assert.equal(f.city.options.length, 1);
    assert.equal(f.save.disabled, true);
    assert.equal(f.load.textContent, 'Reintentar carga');
});

scenario('switching back to a loaded state cancels pending work and ignores its eventual response', async f => {
    f.change('2');
    f.change('1');
    assert.equal(f.requests[0].options.signal.aborted, true);
    assert.equal(f.city.options[1].value, '11');
    await f.respond(0, 2, [{ id: 22, name: 'Tardío' }]);
    assert.equal(f.city.options[1].value, '11');
});

scenario('cleanup aborts requests, removes listeners and ignores subsequent network completion', async f => {
    f.change('2');
    f.dispose();
    assert.equal(f.requests[0].options.signal.aborted, true);
    f.change('3');
    assert.equal(f.requests.length, 1);
    await f.respond(0, 2, [{ id: 22, name: 'No insertar' }]);
    assert.equal(f.city.options.length, 1);
    f.unchanged();
});

test('late enhancement recognizes a state changed before JavaScript mounted instead of trusting stale SSR options', async () => {
    const f = fixture();
    try {
        f.state.value = '2';
        f.mount();
        assert.equal(f.requests.length, 1);
        assert.equal(f.city.disabled, true);
        await f.respond(0, 2, [{ id: 22, name: 'Estado elegido antes de montar' }]);
        assert.equal(f.city.options[1].value, '22');
        assert.equal(f.city.value, '');
        f.unchanged();
    } finally { f.restore(); }
});

test('real Turbo page lifecycle mounts once and a return visit starts only one catalog request', async () => {
    const f = fixture();
    try {
        onPage('[data-profile-location]', setupProfileLocation);
        f.document.dispatchEvent(new Event('turbo:load'));
        f.change('2');
        assert.equal(f.requests.length, 1);
        f.document.dispatchEvent(new Event('turbo:before-cache'));
        assert.equal(f.root.dataset.locationUnresolved, 'true');
        assert.equal(f.requests[0].options.signal.aborted, true);
        f.document.dispatchEvent(new Event('turbo:before-render'));
        f.document.dispatchEvent(new Event('turbo:load'));
        f.document.dispatchEvent(new Event('turbo:load'));
        assert.equal(f.requests.length, 2, 'cached unresolved state must reload, with one controller');
        await f.respond(1, 2, [{ id: 22, name: 'Vuelta' }]);
        await f.respond(0, 2, [{ id: 99, name: 'Vieja página' }]);
        assert.equal(f.city.options[1].value, '22');
        f.document.dispatchEvent(new Event('turbo:before-render'));
    } finally { f.restore(); }
});
