import test from 'node:test';
import assert from 'node:assert/strict';
import { setupPublicNavigation } from '../../resources/js/ui/public-navigation.js';
import { onPage } from '../../resources/js/ui/page.js';

function harness({ mount = true } = {}) {
    const previous = { document: globalThis.document, window: globalThis.window };
    const document = new EventTarget(), window = new EventTarget();
    document.body = { style: { overflow: '' } };
    globalThis.document = document; globalThis.window = window;
    const node = parent => {
        const item = new EventTarget(); item.parent = parent; item.attributes = {};
        item.setAttribute = (name, value) => { item.attributes[name] = value; };
        item.focus = () => { document.activeElement = item; item.focusCount = (item.focusCount ?? 0) + 1; };
        return item;
    };
    const makeRoot = () => {
        const root = node(), trigger = node(root), panel = node(root);
        const first = node(panel), last = node(panel);
        first.closest = last.closest = function () { return this; };
        let open = false;
        Object.defineProperty(root, 'open', { get: () => open, set: value => { open = value; root.dispatchEvent(new Event('toggle')); } });
        root.contains = item => { for (; item; item = item.parent) if (item === root) return true; return false; };
        root.querySelector = selector => selector === '[data-account-trigger]' ? trigger : panel;
        panel.querySelectorAll = () => [first, last];
        return { root, trigger, panel, first, last };
    };
    let current = makeRoot();
    document.querySelector = () => current.root;
    const controller = new AbortController(), cleanups = [];
    const lifecycle = { listen: (target, type, callback) => target.addEventListener(type, callback, { signal: controller.signal }), onCleanup: callback => cleanups.push(callback) };
    if (mount) setupPublicNavigation(current.root, lifecycle);
    const fire = (target, type, props = {}) => {
        const event = new Event(type, { cancelable: true });
        for (const [key, value] of Object.entries(props)) Object.defineProperty(event, key, { value });
        target.dispatchEvent(event); return event;
    };
    return { ...current, document, window, fire,
        key: key => fire(current.root, 'keydown', { key, target: document.activeElement ?? current.trigger }),
        next() { current = makeRoot(); return current; },
        cleanup() { controller.abort(); cleanups.forEach(callback => callback()); },
        restore() { controller.abort(); globalThis.document = previous.document; globalThis.window = previous.window; } };
}

test('public disclosure follows native toggles, keeps Enter native and synchronizes expanded state', () => {
    const h = harness();
    try {
        assert.equal(h.trigger.attributes['aria-expanded'], 'false');
        assert.equal(h.key('Enter').defaultPrevented, false);
        h.root.open = true;
        assert.equal(h.trigger.attributes['aria-expanded'], 'true');
        h.root.open = false;
        assert.equal(h.trigger.attributes['aria-expanded'], 'false');
    } finally { h.restore(); }
});

test('public keyboard supports arrows Home End and Escape with focus returned to its summary', () => {
    const h = harness();
    try {
        h.key('ArrowDown'); assert.equal(h.document.activeElement, h.first);
        assert.equal(h.trigger.attributes['aria-expanded'], 'true');
        h.key('End'); assert.equal(h.document.activeElement, h.last);
        h.key('Home'); assert.equal(h.document.activeElement, h.first);
        h.key('Escape'); assert.equal(h.document.activeElement, h.trigger);
        assert.equal(h.root.open, false); assert.equal(h.trigger.attributes['aria-expanded'], 'false');
    } finally { h.restore(); }
});

test('public navigation and outside focus close the disclosure without trapping Tab or blocking links', () => {
    const h = harness();
    try {
        h.root.open = true;
        assert.equal(h.key('Tab').defaultPrevented, false);
        assert.equal(h.fire(h.panel, 'click', { target: h.first }).defaultPrevented, false);
        assert.equal(h.root.open, false);
        h.root.open = true;
        h.fire(h.root, 'focusout', { relatedTarget: {} });
        assert.equal(h.root.open, false); assert.equal(h.trigger.attributes['aria-expanded'], 'false');
    } finally { h.restore(); }
});

test('public history restore and Turbo cleanup close and synchronize without restoring detached focus', () => {
    const h = harness();
    try {
        h.root.open = true; h.fire(h.window, 'pageshow', { persisted: true });
        assert.equal(h.root.open, false);
        h.root.open = true; h.fire(h.document, 'turbo:before-cache');
        assert.equal(h.trigger.attributes['aria-expanded'], 'false');
        h.root.open = true; h.cleanup();
        assert.equal(h.root.open, false); assert.equal(h.trigger.attributes['aria-expanded'], 'false');
        assert.equal(h.key('ArrowDown').defaultPrevented, false);
    } finally { h.restore(); }
});

test('real page lifecycle mounts once and removes public listeners across successive Turbo bodies', () => {
    const h = harness({ mount: false });
    try {
        let mounts = 0;
        onPage('[data-public-navigation]', (root, lifecycle) => { mounts++; setupPublicNavigation(root, lifecycle); });
        for (let index = 0; index < 5; index++) {
            h.fire(h.document, 'turbo:load'); h.fire(h.document, 'turbo:load');
            const current = h.document.querySelector();
            const trigger = current.querySelector('[data-account-trigger]');
            h.fire(current, 'keydown', { key: 'ArrowDown', target: trigger });
            assert.equal(h.document.activeElement.focusCount, 1);
            h.fire(h.document, 'turbo:before-render');
            assert.equal(current.open, false); assert.equal(trigger.attributes['aria-expanded'], 'false');
            assert.equal(h.fire(current, 'keydown', { key: 'ArrowDown', target: trigger }).defaultPrevented, false);
            h.next();
        }
        assert.equal(mounts, 5);
    } finally { h.restore(); }
});
