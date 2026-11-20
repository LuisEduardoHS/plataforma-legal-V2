// Share lifecycle state with modules that finish importing during navigation.
// A late module may register controllers, but must not mount the outgoing body.
const lifecycles = new WeakMap();

function safely(callback) {
    try { callback(); }
    catch (error) { console.error('No se pudo completar el ciclo de la pantalla.', error); }
}

function lifecycle() {
    if (lifecycles.has(document)) return lifecycles.get(document);
    const state = { active: true, visiting: false, controllers: new Set(), loaders: new Set(), pending: new Set() };
    lifecycles.set(document, state);
    let reloadOnRestore = false;
    const activate = () => {
        state.active = true;
        state.visiting = false;
        state.loaders.forEach((load) => load());
        state.controllers.forEach((controller) => safely(() => controller.mount()));
    };
    const dispose = () => {
        state.active = false;
        state.visiting = false;
        state.controllers.forEach((controller) => safely(() => controller.dispose()));
    };
    const beforeVisit = () => {
        state.visiting = true;
        state.controllers.forEach((controller) => safely(() => controller.beforeVisit?.()));
    };
    document.addEventListener('turbo:visit', beforeVisit);
    document.addEventListener('turbo:fetch-request-error', () => { state.visiting = false; });
    document.addEventListener('turbo:before-render', dispose);
    document.addEventListener('turbo:load', activate);
    window.addEventListener('pagehide', (event) => {
        state.active = false;
        state.visiting = false;
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

export function isVisiting() {
    return Boolean(lifecycles.get(document)?.visiting);
}

function normalizeUrl(raw, base = 'http://localhost') {
    if (!raw) return null;
    try {
        const parsed = new URL(String(raw), base);
        return `${parsed.origin}${parsed.pathname}${parsed.search}`;
    } catch {
        return null;
    }
}

export function setupNavigationFeedback(doc = document, win = window) {
    let pendingUrl = null;
    const base = () => win?.location?.href || 'http://localhost';

    const clearPendingIndicators = () => {
        pendingUrl = null;
        doc.querySelectorAll?.('[data-nav-pending]')?.forEach((node) => {
            node.removeAttribute('data-nav-pending');
        });
    };

    doc.addEventListener?.('turbo:click', (event) => {
        const targetUrl = normalizeUrl(event.detail?.url, base());
        if (!targetUrl) return;

        // Deduplicate rapid clicks to the exact same destination while a visit is already in flight.
        if (pendingUrl && pendingUrl === targetUrl) {
            event.preventDefault();
            return;
        }

        const link = event.target?.closest?.('a[href]');
        if (!link || pendingUrl) return;

        const currentUrl = normalizeUrl(win?.location?.href, base());
        if (!currentUrl || targetUrl !== currentUrl) return;

        const isCurrentConversation = link.matches?.('.messages-conversation[aria-current="true"]');
        if (isCurrentConversation) {
            const messagingRoot = doc.querySelector?.('[data-messaging]');
            if (messagingRoot?.dataset?.view === 'list') {
                messagingRoot.dataset.view = 'chat';
            }
            event.preventDefault();
            return;
        }

        const isCurrentPrimaryNav = link.matches?.('.headerNav-button[aria-current="page"], .lexa-header-btn[aria-current="page"]');
        if (isCurrentPrimaryNav) {
            const hasActiveError = Boolean(
                doc.querySelector?.('[data-messaging-recovery]:not([hidden]), .lexa-banner:not([hidden]), [role="alert"]:not([hidden])')
            );
            if (!hasActiveError) {
                event.preventDefault();
            }
        }
    });

    doc.addEventListener?.('turbo:visit', (event) => {
        clearPendingIndicators();
        pendingUrl = normalizeUrl(event.detail?.url, base());
        if (!pendingUrl) return;
        doc.querySelectorAll?.('.headerNav-button, .lexa-header-btn, .messages-conversation')?.forEach((link) => {
            const href = normalizeUrl(link.getAttribute?.('href') || link.href, base());
            if (href === pendingUrl) {
                link.setAttribute('data-nav-pending', 'true');
            }
        });
    });

    doc.addEventListener?.('turbo:load', (event) => {
        clearPendingIndicators();
        if (!event.detail?.timing?.visitStart) return;
        if (doc.activeElement !== doc.body) return;
        doc.querySelector?.('#main-content')?.focus({ preventScroll: true });
    });

    doc.addEventListener?.('turbo:before-cache', clearPendingIndicators);
    doc.addEventListener?.('turbo:fetch-request-error', clearPendingIndicators);
    win?.addEventListener?.('popstate', clearPendingIndicators);

    return {
        getPendingUrl: () => pendingUrl,
        clearPendingIndicators,
    };
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
    let visitHook;
    const dispose = () => {
        visitHook = null;
        const previous = cleanup;
        cleanup = null;
        previous?.();
    };
    const beforeVisit = () => { visitHook?.(); };
    const mount = () => {
        if (!state.active) return;
        const root = document.querySelector(selector);
        if (root === activeRoot) return;
        dispose();
        if (!root) return;
        activeRoot = root;
        const controller = new AbortController();
        const cleanups = [];
        const beforeVisits = [];
        const body = document.body;
        const overflow = body.style.overflow;
        visitHook = () => {
            beforeVisits.forEach((cb) => safely(() => cb()));
        };
        cleanup = () => {
            controller.abort();
            cleanups.forEach((fn) => safely(() => fn({ persisted: false })));
            body.style.overflow = overflow;
            activeRoot = null;
        };
        try {
            setup(root, {
                listen(target, type, callback, options) {
                    const opts = typeof options === 'object' && options !== null
                        ? { ...options, signal: controller.signal }
                        : { signal: controller.signal };
                    target?.addEventListener(type, callback, opts);
                },
                onCleanup(callback) { cleanups.push(callback); },
                onBeforeVisit(callback) { beforeVisits.push(callback); },
                isVisiting() { return state.visiting; },
            });
        } catch (error) { dispose(); throw error; }
    };
    state.controllers.add({ selector, setup, mount, dispose, beforeVisit });
    mount();
}
