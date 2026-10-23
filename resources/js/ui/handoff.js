const prefix = 'legal.ui.handoff.';
export function writeHandoff(target, value, storage = sessionStorage) {
    storage.setItem(prefix + target, JSON.stringify({ value, expires: Date.now() + 600000 }));
}
export function takeHandoff(target, storage) {
    try {
        storage ??= sessionStorage;
        const raw = storage.getItem(prefix + target);
        storage.removeItem(prefix + target);
        if (!raw) return null;
        const item = JSON.parse(raw);
        return item.expires > Date.now() && item.value && typeof item.value === 'object' ? item.value : null;
    } catch { return null; }
}
