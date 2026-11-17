import test from 'node:test';
import assert from 'node:assert/strict';
import { setupAccountMenu } from '../../resources/js/ui/account-menu.js';

function harness() {
    const previous = { document: globalThis.document, window: globalThis.window };
    const document = { activeElement: null }, window = {};
    globalThis.document = document; globalThis.window = window;
    const make = (parent, anchor = false) => ({
        parent, anchor, focus() { document.activeElement = this; },
        closest() { return this.anchor ? this : this.parent?.closest?.() ?? null; },
    });
    const root = make(null); root.open = false;
    const trigger = make(root), panel = make(root);
    const first = make(panel, true), last = make(panel, true), logout = make(panel);
    const nodes = [first, last, logout];
    const outside = make(null);
    root.contains = (node) => { for (; node; node = node.parent) if (node === root) return true; return false; };
    root.querySelector = (selector) => selector === '[data-account-trigger]' ? trigger : panel;
    panel.querySelectorAll = () => nodes;
    const listeners = [], cleanups = [];
    setupAccountMenu(root, {
        listen(target, type, callback) { listeners.push({ target, type, callback }); },
        onCleanup(callback) { cleanups.push(callback); },
    });
    const fire = (target, type, values = {}) => {
        const event = { target, prevented: false, stopped: false,
            preventDefault() { this.prevented = true; }, stopPropagation() { this.stopped = true; }, ...values };
        listeners.filter(l => l.target === target && l.type === type).forEach(l => l.callback(event));
        return event;
    };
    return { root, trigger, panel, first, last, logout, nodes, outside, document, window, fire, cleanups,
        key: (key, target = trigger) => fire(root, 'keydown', { key, target }),
        restore() { globalThis.document = previous.document; globalThis.window = previous.window; } };
}

test('account disclosure uses native open state and keyboard focus wraps with Home End and Escape', () => {
    const h = harness();
    try {
        assert.equal(h.key('ArrowDown').prevented, true);
        assert.equal(h.root.open, true); assert.equal(h.document.activeElement, h.first);
        h.key('ArrowUp', h.first); assert.equal(h.document.activeElement, h.logout);
        h.key('Home', h.logout); assert.equal(h.document.activeElement, h.first);
        h.key('End', h.first); assert.equal(h.document.activeElement, h.logout);
        const escape = h.key('Escape', h.logout);
        assert.equal(escape.prevented, true); assert.equal(escape.stopped, true);
        assert.equal(h.root.open, false); assert.equal(h.document.activeElement, h.trigger);
        assert.equal(h.key('Escape').prevented, false);
    } finally { h.restore(); }
});

test('account ArrowUp starts at last item and an empty menu does not fail', () => {
    const h = harness();
    try {
        h.key('ArrowUp'); assert.equal(h.document.activeElement, h.logout);
        h.root.open = false; h.nodes.length = 0;
        assert.doesNotThrow(() => h.key('ArrowDown'));
        assert.equal(h.root.open, true);
    } finally { h.restore(); }
});

test('account disclosure never traps Tab and closes only after focus leaves', () => {
    const h = harness();
    try {
        h.root.open = true;
        assert.equal(h.key('Tab', h.first).prevented, false);
        h.fire(h.root, 'focusout', { relatedTarget: h.last }); assert.equal(h.root.open, true);
        h.outside.focus();
        h.fire(h.root, 'focusout', { relatedTarget: h.outside });
        assert.equal(h.root.open, false); assert.equal(h.document.activeElement, h.outside);
    } finally { h.restore(); }
});

test('outside pointer closes the account panel without moving focus or interfering with native toggle', () => {
    const h = harness();
    try {
        h.root.open = true;
        h.fire(h.document, 'pointerdown', { target: h.trigger }); assert.equal(h.root.open, true);
        h.outside.focus();
        const event = h.fire(h.document, 'pointerdown', { target: h.outside });
        assert.equal(h.root.open, false); assert.equal(event.prevented, false);
        assert.equal(h.document.activeElement, h.outside);
    } finally { h.restore(); }
});

test('account navigation and POST submission close without preventing their native actions', () => {
    const h = harness();
    try {
        h.root.open = true;
        const navigate = h.fire(h.panel, 'click', { target: h.first });
        assert.equal(h.root.open, false); assert.equal(navigate.prevented, false);
        h.root.open = true;
        h.fire(h.panel, 'click', { target: h.logout }); assert.equal(h.root.open, true);
        const submit = h.fire(h.root, 'submit');
        assert.equal(h.root.open, false); assert.equal(submit.prevented, false);
    } finally { h.restore(); }
});

test('account state is closed before Turbo caches and when a history page is restored', () => {
    const h = harness();
    try {
        h.root.open = true; h.fire(h.document, 'turbo:before-cache');
        assert.equal(h.root.open, false);
        h.root.open = true; h.fire(h.window, 'pageshow', { persisted: true });
        assert.equal(h.root.open, false);
    } finally { h.restore(); }
});

test('page lifecycle cleanup releases the account open state without restoring focus into removed DOM', () => {
    const h = harness();
    try {
        h.root.open = true; h.outside.focus();
        h.cleanups.forEach(dispose => dispose());
        assert.equal(h.root.open, false); assert.equal(h.document.activeElement, h.outside);
        h.cleanups.forEach(dispose => dispose());
        assert.equal(h.document.activeElement, h.outside);
    } finally { h.restore(); }
});
