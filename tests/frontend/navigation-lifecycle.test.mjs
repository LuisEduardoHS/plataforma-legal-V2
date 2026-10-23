import test from 'node:test';
import assert from 'node:assert/strict';
import { onPage } from '../../resources/js/ui/page.js';

test('navigation mounts once per body and releases old global listeners and resources', () => {
    const previousDocument = globalThis.document;
    const previousWindow = globalThis.window;
    let root = {};
    const doc = new EventTarget();
    doc.querySelector = () => root;
    doc.body = { style: { overflow: '' } };
    globalThis.document = doc;
    globalThis.window = new EventTarget();
    try {
        let mounts = 0, events = 0, releases = 0;
        onPage('[data-page]', (_, { listen, onCleanup }) => {
            mounts++;
            listen(window, 'resize', () => events++);
            onCleanup(({ persisted }) => { assert.equal(persisted, false); releases++; });
        });
        doc.dispatchEvent(new Event('turbo:load'));
        assert.equal(mounts, 1, 'initial turbo:load must not duplicate listeners');
        window.dispatchEvent(new Event('resize'));
        assert.equal(events, 1);
        doc.body.style.overflow = 'hidden';
        doc.dispatchEvent(new Event('turbo:before-render'));
        assert.equal(releases, 1);
        assert.equal(doc.body.style.overflow, '');
        window.dispatchEvent(new Event('resize'));
        assert.equal(events, 1, 'old controller must stop receiving events');
        root = null;
        doc.dispatchEvent(new Event('turbo:load'));
        assert.equal(mounts, 1, 'unrelated pages must not mount');
        root = {};
        doc.dispatchEvent(new Event('turbo:load'));
        assert.equal(mounts, 2, 'return navigation must initialize the new DOM');
        window.dispatchEvent(new Event('resize'));
        assert.equal(events, 2);
        doc.dispatchEvent(new Event('turbo:before-render'));
        doc.dispatchEvent(new Event('turbo:before-render'));
        assert.equal(releases, 2, 'resources must be disposed once');
    } finally {
        globalThis.document = previousDocument;
        globalThis.window = previousWindow;
    }
});
