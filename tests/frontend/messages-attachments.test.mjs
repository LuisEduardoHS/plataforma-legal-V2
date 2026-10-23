import test from 'node:test';
import assert from 'node:assert/strict';
import { validateAttachment, createAttachment, releaseAttachment } from '../../resources/js/messages/attachments.js';

const file = (name, type, size = 1024) => ({ name, type, size, lastModified: 1 });
test('accepts supported formats and an OS without MIME metadata', () => {
    for (const [name, type] of [
        ['foto.JPG', 'image/jpeg'], ['foto.png', 'image/png'], ['foto.webp', 'image/webp'],
        ['archivo.pdf', 'application/pdf'],
        ['archivo.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        ['clip.mp4', 'video/mp4'], ['clip.webm', 'video/webm'], ['foto.png', ''],
    ]) assert.equal(validateAttachment(file(name, type), []), null, name);
});
test('rejects mismatched MIME, unsupported formats and empty files', () => {
    assert.ok(validateAttachment(file('foto.png', 'text/html'), []));
    assert.ok(validateAttachment(file('archivo.exe', ''), []));
    assert.ok(validateAttachment(file('foto.png', 'image/png', 0), []));
});
test('enforces per-file boundaries', () => {
    for (const [name, type, max] of [['foto.png', 'image/png', 10], ['archivo.pdf', 'application/pdf', 20], ['clip.mp4', 'video/mp4', 50]]) {
        assert.equal(validateAttachment(file(name, type, max * 1024 ** 2), []), null);
        assert.ok(validateAttachment(file(name, type, max * 1024 ** 2 + 1), []));
    }
});
test('enforces attachment count, total size and duplicates', () => {
    const image = file('foto.png', 'image/png');
    assert.ok(validateAttachment(image, [image]));
    assert.ok(validateAttachment(image, Array.from({ length: 4 }, (_, n) => file(n + '.png', 'image/png'))));
    assert.ok(validateAttachment(image, [file('a.mp4', 'video/mp4', 40 * 1024 ** 2), file('b.mp4', 'video/mp4', 40 * 1024 ** 2)]));
});
test('creates a usable local URL and revokes it on release', async () => {
    const selected = new File(['example'], 'archivo.pdf', { type: 'application/pdf' });
    const item = createAttachment(selected);
    assert.equal(item.kind, 'document');
    assert.equal(item.local, true);
    assert.equal(await (await fetch(item.url)).text(), 'example');
    releaseAttachment(item);
    await assert.rejects(fetch(item.url));
    // Supplied backend/example URLs must never be revoked.
    releaseAttachment({ local: false, url: '/demo/messages/contrato-ejemplo.pdf' });
});
