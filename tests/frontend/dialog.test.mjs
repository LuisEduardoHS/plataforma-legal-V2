import test from 'node:test';
import assert from 'node:assert/strict';
import { setupDialog } from '../../resources/js/ui/dialog.js';
import { setupValidation } from '../../resources/js/ui/validation.js';
import { onPage } from '../../resources/js/ui/page.js';

function harness(native = true) {
    const previous = globalThis.document;
    const document = { body: { style: { overflow: 'auto' } } };
    globalThis.document = document;
    const trigger = new EventTarget(), cancel = new EventTarget(), dialog = new EventTarget();
    trigger.isConnected = true; trigger.attributes = {};
    trigger.setAttribute = (key, value) => { trigger.attributes[key] = value; };
    trigger.focus = () => { document.activeElement = trigger; };
    cancel.focus = () => { document.activeElement = cancel; };
    dialog.id = 'logout'; dialog.open = false;
    if (native) dialog.showModal = () => { dialog.open = true; };
    dialog.close = () => { if (!dialog.open) return; dialog.open = false; dialog.dispatchEvent(new Event('close')); };
    dialog.querySelector = () => cancel; dialog.querySelectorAll = () => [cancel];
    const root = { querySelector: (selector) => selector === 'dialog' ? dialog : trigger };
    const controller = new AbortController(), cleanups = [];
    const context = { listen: (target, type, handler) => target.addEventListener(type, handler, { signal: controller.signal }), onCleanup: (fn) => cleanups.push(fn) };
    return { document, trigger, cancel, dialog, root, context, cleanups, controller,
        click(target) { const event = new Event('click', { cancelable: true }); target.dispatchEvent(event); return event; },
        restore() { controller.abort(); globalThis.document = previous; } };
}

test('native modal enhancement cancels fallback submission, focuses Cancel and restores scroll and opener', () => {
    const h = harness();
    try {
        setupDialog(h.root, h.context);
        assert.equal(h.click(h.trigger).defaultPrevented, true);
        assert.equal(h.dialog.open, true);
        assert.equal(h.document.activeElement, h.cancel);
        assert.equal(h.document.body.style.overflow, 'hidden');
        assert.equal(h.trigger.attributes['aria-controls'], 'logout');
        h.click(h.cancel);
        assert.equal(h.dialog.open, false);
        assert.equal(h.document.activeElement, h.trigger);
        assert.equal(h.document.body.style.overflow, 'auto');
    } finally { h.restore(); }
});

test('native close event including Escape restores the opener; repeated clicks keep original scroll', () => {
    const h = harness();
    try {
        setupDialog(h.root, h.context);
        h.click(h.trigger); h.click(h.trigger); h.dialog.close();
        assert.equal(h.document.body.style.overflow, 'auto');
        assert.equal(h.document.activeElement, h.trigger);
        h.click(h.trigger); h.trigger.isConnected = false; h.dialog.close();
        assert.equal(h.document.activeElement, h.cancel);
    } finally { h.restore(); }
});

test('Turbo cleanup works after listener abortion and avoids focusing detached content', () => {
    const h = harness();
    try {
        setupDialog(h.root, h.context); h.click(h.trigger);
        h.controller.abort(); h.cleanups.forEach(fn => fn());
        assert.equal(h.dialog.open, false);
        assert.equal(h.document.body.style.overflow, 'auto');
        assert.equal(h.document.activeElement, h.cancel);
        assert.equal(h.click(h.trigger).defaultPrevented, false);
    } finally { h.restore(); }
});

test('unsupported native dialog leaves the real POST fallback available', () => {
    const h = harness(false);
    try {
        setupDialog(h.root, h.context);
        assert.equal(h.click(h.trigger).defaultPrevented, false);
        assert.deepEqual(h.trigger.attributes, {});
    } finally { h.restore(); }
});

test('validation focuses the summary only when focus is on the page, never while typing', () => {
    const previous = globalThis.document;
    try {
        let calls = 0;
        const body = {}, summary = { focus() { calls++; } };
        globalThis.document = { body, activeElement: body };
        setupValidation(summary);
        document.activeElement = { id: 'main-content' }; setupValidation(summary);
        document.activeElement = { id: 'title' }; setupValidation(summary);
        assert.equal(calls, 2);
    } finally { globalThis.document = previous; }
});

test('real onPage lifecycle mounts once per body and removes dialog handlers before Turbo remount', () => {
    const previous = { document: globalThis.document, window: globalThis.window };
    const h = harness();
    const document = Object.assign(new EventTarget(), h.document);
    globalThis.document = document; globalThis.window = new EventTarget();
    document.querySelector = () => h.root;
    let mounts = 0;
    try {
        onPage('[data-dialog-root]', (root, context) => { mounts++; setupDialog(root, context); });
        document.dispatchEvent(new Event('turbo:load')); document.dispatchEvent(new Event('turbo:load'));
        assert.equal(mounts, 1); h.click(h.trigger);
        document.dispatchEvent(new Event('turbo:before-render'));
        assert.equal(h.dialog.open, false); assert.equal(document.body.style.overflow, 'auto');
        assert.equal(h.click(h.trigger).defaultPrevented, false);
        document.dispatchEvent(new Event('turbo:load')); assert.equal(mounts, 2);
        h.click(h.trigger); globalThis.window.dispatchEvent(new Event('pagehide'));
        assert.equal(h.dialog.open, false); assert.equal(document.body.style.overflow, 'auto');
    } finally { h.restore(); globalThis.document = previous.document; globalThis.window = previous.window; }
});
