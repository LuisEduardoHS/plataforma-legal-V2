// Share lifecycle state with modules that finish importing during navigation.
// A late module may register controllers, but must not mount the outgoing body.
const lifecycles = new WeakMap();

function safely(callback) {
    try { callback(); }
    catch (error) { console.error('No se pudo completar el ciclo de la pantalla.', error); }
}

function lifecycle() {
    if (lifecycles.has(document)) return lifecycles.get(document);
    const state = { active: true, controllers: new Set(), loaders: new Set(), pending: new Set() };
    lifecycles.set(document, state);
    let reloadOnRestore = false;
    const activate = () => {
        state.active = true;
        state.loaders.forEach((load) => load());
        state.controllers.forEach((controller) => safely(() => controller.mount()));
    };
    const dispose = () => {
        state.active = false;
        state.controllers.forEach((controller) => safely(() => controller.dispose()));
    };
    document.addEventListener('turbo:before-render', dispose);
    document.addEventListener('turbo:load', activate);
    window.addEventListener('pagehide', (event) => {
        state.active = false;
        if (!event.persisted) dispose();
        // Pending modules have not yet installed their own BFCache safeguards.
        // Restore through the server rather than mount a potentially stale page.
        else if (state.pending.size) reloadOnRestore = true;
    });
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) return;
        if (reloadOnRestore) { window.location.reload(); return; }
        activate();
    });
    return state;
}

// Import each page module once, only when its marker is present. ES modules keep
// their onPage registrations for later Turbo visits; failures can retry on a visit.
export function loadOnPage(selector, importer) {
    const state = lifecycle();
    let loaded = false, pending;
    const load = () => {
        if (!state.active || loaded || pending || !document.querySelector(selector)) return;
        pending = Promise.resolve().then(importer).then(() => { loaded = true; })
            .catch((error) => { console.error('No se pudo cargar el módulo de la pantalla.', error); })
            .finally(() => { state.pending.delete(pending); pending = null; });
        state.pending.add(pending);
    };
    state.loaders.add(load);
    load();
}

// Modules are evaluated once, but each new Blade body needs fresh controllers.
// Abort listeners and release resources before the old body is removed.
export function onPage(selector, setup) {
    const state = lifecycle();
    if ([...state.controllers].some((controller) => controller.selector === selector && controller.setup === setup)) return;
    let activeRoot;
    let cleanup;
    const dispose = () => { const previous = cleanup; cleanup = null; previous?.(); };
    const mount = () => {
        if (!state.active) return;
        const root = document.querySelector(selector);
        if (root === activeRoot) return;
        dispose();
        if (!root) return;
        activeRoot = root;
        const controller = new AbortController();
        const cleanups = [];
        const body = document.body;
        const overflow = body.style.overflow;
        cleanup = () => {
            controller.abort();
            cleanups.forEach((cleanup) => safely(() => cleanup({ persisted: false })));
            body.style.overflow = overflow;
            activeRoot = null;
        };
        try {
            setup(root, {
                listen(target, type, callback) {
                    target?.addEventListener(type, callback, { signal: controller.signal });
                },
                onCleanup(callback) { cleanups.push(callback); },
            });
        } catch (error) { dispose(); throw error; }
    };
    state.controllers.add({ selector, setup, mount, dispose });
    mount();
}
