import test from 'node:test';
import assert from 'node:assert/strict';
import { createDraft, createSession, appendExchange, matchesSession, validateContext } from '../../resources/js/lexa/state.js';

test('history search ignores accents and case', () => {
    const session = { title: 'Orientación sobre mi renta' };
    assert.equal(matchesSession(session, 'ORIENTACION'), true);
    assert.equal(matchesSession(session, '  '), true);
    assert.equal(matchesSession(session, 'laboral'), false);
});
test('new queries have unique identities and independent drafts', () => {
    const a = createSession('  Mi consulta\ncon contexto ', 'contract');
    const b = createSession('Otra', 'orientation');
    assert.notEqual(a.id, b.id);
    assert.equal(a.title, 'Mi consulta con contexto');
    a.draft.text = 'Borrador';
    a.draft.attachments.push({ id: 'file' });
    assert.equal(b.draft.text, '');
    assert.equal(b.draft.attachments.length, 0);
    assert.ok(createSession('x'.repeat(100), 'contract').title.length <= 72);
});
test('empty input does not fabricate an exchange', () => {
    const session = createSession('', 'orientation');
    assert.equal(appendExchange(session, createDraft(), 'Ejemplo'), false);
    assert.equal(session.messages.length, 0);
});
test('sending transfers files, preserves text and labels the example response', () => {
    const session = createSession('Contrato', 'contract');
    const draft = createDraft('contract');
    const file = { id: 'file', name: 'contrato.pdf', url: 'blob:local' };
    draft.text = '  <img src=x> Mi pregunta  ';
    draft.attachments.push(file);
    assert.equal(appendExchange(session, draft, 'Respuesta de ejemplo'), true);
    assert.equal(session.messages[0].text, '<img src=x> Mi pregunta');
    assert.equal(session.messages[0].attachments[0], file);
    assert.equal(session.messages[1].example, true);
    assert.equal(session.draft.text, '');
    assert.equal(session.draft.attachments.length, 0);
});
test('context-only input produces a readable question', () => {
    const session = createSession('', 'organize');
    const draft = createDraft('organize');
    draft.attachments.push({ id: 'file' });
    assert.equal(appendExchange(session, draft, 'Ejemplo'), true);
    assert.ok(session.messages[0].text.length);
});
test('LEXA validates document/image context and rejects videos or oversized files', () => {
    const file = (name, type, size = 100) => ({ name, type, size });
    assert.equal(validateContext(file('contexto.pdf', 'application/pdf'), []), null);
    assert.equal(validateContext(file('contexto.png', 'image/png'), []), null);
    assert.ok(validateContext(file('clip.mp4', 'video/mp4'), []));
    assert.ok(validateContext(file('archivo.exe', ''), []));
    assert.ok(validateContext(file('foto.jpg', 'image/jpeg', 10 * 1024 ** 2 + 1), []));
});
