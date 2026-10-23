export const STORAGE_KEY = 'plataforma-legal.saved.v1';
export const normalize = (value = '') => String(value).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();

export function validItem(item) {
    return item && ['article', 'post'].includes(item.kind)
        && typeof item.id === 'string' && /^[\w-]{1,100}$/.test(item.id)
        && typeof item.title === 'string' && item.title.trim().length > 0 && item.title.length <= 300
        && typeof item.href === 'string' && /^\/(inicio|comunidad|articulos)(#[\w-]+)?$/.test(item.href)
        && Number.isFinite(item.savedAt) && item.savedAt > 0 && item.savedAt <= 8640000000000000;
}
export const itemKey = (item) => `${item.kind}:${item.id}`;
export function readSaved(storage) {
    const data = JSON.parse(storage.getItem(STORAGE_KEY) || '[]');
    if (!Array.isArray(data)) return [];
    const unique = new Map();
    for (const item of data.slice(0, 300)) {
        if (validItem(item)) unique.set(itemKey(item), {
            id: item.id, kind: item.kind, title: item.title, href: item.href, savedAt: item.savedAt,
            category: String(item.category || '').slice(0, 100),
            summary: String(item.summary || '').slice(0, 500), author: String(item.author || '').slice(0, 100),
        });
    }
    return [...unique.values()];
}
export function selectSaved(items, { query = '', kind = 'all', order = 'recent' } = {}) {
    const words = normalize(query).split(/\s+/).filter(Boolean);
    return items.filter((item) => (kind === 'all' || item.kind === kind)
        && words.every((word) => normalize([item.title, item.category, item.author, item.summary].join(' ')).includes(word)))
        .sort((a, b) => order === 'oldest' ? a.savedAt - b.savedAt : b.savedAt - a.savedAt);
}
export function createLibrary(getStorage, notify = () => {}) {
    const read = () => {
        try { return readSaved(getStorage()); } catch { return []; }
    };
    const write = (items) => {
        getStorage().setItem(STORAGE_KEY, JSON.stringify(items));
        notify();
    };
    return {
        read,
        has(kind, id) { return read().some((item) => item.kind === kind && item.id === id); },
        add(record) {
            const item = { ...record, savedAt: record.savedAt || Date.now() };
            if (!validItem(item)) throw new Error('Invalid saved item');
            const items = read().filter((entry) => itemKey(entry) !== itemKey(item));
            if (items.length >= 300) throw new Error('Library limit reached');
            write([...items, item]);
            return item;
        },
        remove(kind, id) { write(read().filter((item) => item.kind !== kind || item.id !== id)); },
        toggle(record) {
            if (this.has(record.kind, record.id)) { this.remove(record.kind, record.id); return false; }
            this.add(record); return true;
        },
    };
}
