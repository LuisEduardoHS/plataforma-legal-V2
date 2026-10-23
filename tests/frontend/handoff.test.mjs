import test from 'node:test';
import assert from 'node:assert/strict';
import { writeHandoff, takeHandoff } from '../../resources/js/ui/handoff.js';
const store = () => {
    const data = new Map();
    return { getItem: key => data.get(key) ?? null, setItem: (key, value) => data.set(key, value), removeItem: key => data.delete(key) };
};
test('transfers a draft once without using the URL', () => {
    const storage = store();
    const value = { text: '<img src=x> Mi consulta', mode: 'contract' };
    writeHandoff('lexa', value, storage);
    assert.deepEqual(takeHandoff('lexa', storage), value);
    assert.equal(takeHandoff('lexa', storage), null);
});
test('isolates the destinations', () => {
    const storage = store();
    writeHandoff('lexa', { sessionId: 'a' }, storage);
    assert.equal(takeHandoff('community', storage), null);
    assert.equal(takeHandoff('lexa', storage).sessionId, 'a');
});
test('discards expired and malformed data', () => {
    const storage = store();
    storage.setItem('legal.ui.handoff.lexa', JSON.stringify({ value: { text: 'viejo' }, expires: 0 }));
    assert.equal(takeHandoff('lexa', storage), null);
    storage.setItem('legal.ui.handoff.lexa', '{');
    assert.equal(takeHandoff('lexa', storage), null);
});
test('handles storage failures without breaking the destination page', () => {
    const blocked = { getItem() { throw new Error('blocked'); }, setItem() { throw new Error('blocked'); } };
    assert.equal(takeHandoff('lexa', blocked), null);
    assert.throws(() => writeHandoff('lexa', { text: 'conservar' }, blocked));
});
