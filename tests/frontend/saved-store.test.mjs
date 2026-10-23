import test from 'node:test';
import assert from 'node:assert/strict';
import { createLibrary, selectSaved, readSaved, STORAGE_KEY } from '../../resources/js/saved/store.js';
const memory = () => { const values = new Map(); return { getItem: key => values.get(key) ?? null, setItem: (key, value) => values.set(key, value) }; };
const item = (id, extras = {}) => ({ id, kind: 'post', title: 'Depósito de renta', href: '/comunidad#post-' + id, category: 'Arrendamiento', author: 'Ana', savedAt: 100, ...extras });
test('a shared library persists, toggles and restores the original saved date without duplicates', () => {
    const storage = memory(); let notifications = 0;
    const library = createLibrary(() => storage, () => notifications++);
    assert.equal(library.toggle(item('renta')), true);
    assert.equal(createLibrary(() => storage).has('post', 'renta'), true);
    library.add(item('renta', { title: 'Actualizado' }));
    assert.equal(library.read().length, 1);
    const removed = library.read()[0];
    assert.equal(library.toggle(removed), false);
    assert.equal(library.read().length, 0);
    library.add(removed);
    assert.equal(library.read()[0].savedAt, 100);
    assert.equal(notifications, 4);
});
test('stored data cannot inject external URLs, scripts, invalid dates or duplicate records', () => {
    const storage = memory();
    storage.setItem(STORAGE_KEY, JSON.stringify([item('one'), item('one'), item('bad', { href: 'javascript:alert(1)' }), item('evil', { href: '//example.com' }), item('date', { savedAt: 1e30 }), item('type', { kind: 'unknown' })]));
    assert.deepEqual(readSaved(storage).map(item => item.id), ['one']);
    storage.setItem(STORAGE_KEY, '{}'); assert.deepEqual(readSaved(storage), []);
});
test('search ignores accents and supports author/category, kind filters and both sort orders', () => {
    const items = [item('old'), item('new', { kind: 'article', author: 'María', savedAt: 200 })];
    assert.deepEqual(selectSaved(items, { query: 'deposito maria' }).map(item => item.id), ['new']);
    assert.equal(selectSaved(items, { query: 'ARRENDAMIENTO', kind: 'post' }).length, 1);
    assert.deepEqual(selectSaved(items, { order: 'oldest' }).map(item => item.id), ['old', 'new']);
    assert.deepEqual(selectSaved(items).map(item => item.id), ['new', 'old']);
});
test('storage errors never report a successful save or silently discard the existing library', () => {
    const storage = memory(); const library = createLibrary(() => storage);
    library.add(item('existing'));
    storage.setItem = () => { throw Error('Quota exceeded'); };
    assert.throws(() => library.add(item('new')));
    assert.throws(() => library.remove('post', 'existing'));
    assert.equal(library.has('post', 'existing'), true);
    assert.equal(library.has('post', 'new'), false);
    const unavailable = createLibrary(() => { throw Error('Storage blocked'); });
    assert.deepEqual(unavailable.read(), []);
    assert.throws(() => unavailable.add(item('new')));
});
