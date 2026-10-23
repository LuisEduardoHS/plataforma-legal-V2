// Modules are evaluated once, but each new Blade body needs fresh controllers.
// Abort global listeners and release preview URLs before the old body is removed.
export function onPage(selector, setup) {
    let activeRoot;
    let dispose;
    const mount = () => {
        const root = document.querySelector(selector);
        if (!root || root === activeRoot) return;
        activeRoot = root;
        const controller = new AbortController();
        const cleanups = [];
        const overflow = document.body.style.overflow;
        dispose = () => {
            controller.abort();
            cleanups.forEach((cleanup) => cleanup({ persisted: false }));
            document.body.style.overflow = overflow;
            activeRoot = null;
        };
        setup(root, {
            listen(target, type, callback) {
                target?.addEventListener(type, callback, { signal: controller.signal });
            },
            onCleanup(callback) { cleanups.push(callback); },
        });
    };
    document.addEventListener('turbo:before-render', () => {
        dispose?.();
        dispose = null;
    });
    window.addEventListener('pagehide', (event) => { if (!event.persisted) dispose?.(); });
    document.addEventListener('turbo:load', mount);
    mount();
}
