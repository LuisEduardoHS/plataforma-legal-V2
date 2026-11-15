import test from 'node:test';
import assert from 'node:assert/strict';
import { normalize, savedResponse } from '../../resources/js/saved/store.js';

test('bookmark state changes only on a valid server acknowledgement for either action', () => {
    assert.equal(savedResponse(true, 200, { saved: true }).saved, true);
    assert.equal(savedResponse(true, 200, { saved: false }).saved, false);
    for (const data of [null, {}, { saved: 'false' }, { saved: 1 }]) assert.throws(() => savedResponse(true, 200, data));
});
test('failed writes never acknowledge success even if a response contains a saved flag', () => {
    for (const status of [401, 403, 404, 419, 422, 429, 500, 503]) assert.throws(() => savedResponse(false, status, { saved: true }));
});
test('bookmark errors provide recovery guidance without forwarding server secrets or HTML', () => {
    assert.throws(() => savedResponse(false, 419, {}), /sesión/);
    assert.throws(() => savedResponse(false, 429, {}), /Espera/);
    assert.throws(() => savedResponse(false, 503, { message: '<script>SQL secret<\/script>' }), { message: 'No pudimos actualizar tus guardados. Inténtalo nuevamente.' });
    assert.equal(savedResponse(true, 200, { saved: true, message: '<script>bad<\/script>' }).message, 'Contenido añadido a tus guardados.');
});
test('help search still handles accents after removing the local demo library', () => {
    assert.equal(normalize('  Información ÚTIL  '), 'informacion util');
    assert.equal(normalize(), '');
});
