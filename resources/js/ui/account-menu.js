// Native details/summary remains fully usable if this enhancement cannot load.
export function setupAccountMenu(root, { listen, onCleanup }) {
    const trigger = root.querySelector('[data-account-trigger]');
    const panel = root.querySelector('[data-account-panel]');
    const close = (restoreFocus = false) => {
        if (!root.open) return;
        root.open = false;
        if (restoreFocus) trigger.focus();
    };
    const items = () => [...panel.querySelectorAll('a[href], button:not(:disabled)')];
    const focusItem = (key) => {
        const links = items();
        if (!links.length) return;
        const current = links.indexOf(document.activeElement);
        const index = key === 'Home' ? 0 : key === 'End' ? links.length - 1
            : current < 0 ? (key === 'ArrowUp' ? links.length - 1 : 0)
                : (current + (key === 'ArrowUp' ? -1 : 1) + links.length) % links.length;
        links[index].focus();
    };

    listen(root, 'keydown', (event) => {
        if (event.key === 'Escape' && root.open) {
            event.preventDefault();
            event.stopPropagation();
            close(true);
        } else if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)
            && (root.open || event.target === trigger)) {
            event.preventDefault();
            root.open = true;
            focusItem(event.key);
        }
    });
    listen(document, 'pointerdown', (event) => { if (!root.contains(event.target)) close(); });
    listen(root, 'focusout', (event) => {
        if (event.relatedTarget && !root.contains(event.relatedTarget)) close();
    });
    listen(panel, 'click', (event) => { if (event.target.closest('a[href]')) close(); });
    listen(root, 'submit', () => close());
    listen(document, 'turbo:before-cache', () => close());
    listen(window, 'pageshow', () => close());
    onCleanup(() => close());
}
