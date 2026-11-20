import test from 'node:test';
import assert from 'node:assert/strict';
import { onPage, setupNavigationFeedback } from '../../resources/js/ui/page.js';

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

test('turbo:visit triggers onBeforeVisit immediately and sets isVisiting until render or error', () => {
    const previousDocument = globalThis.document;
    const previousWindow = globalThis.window;
    const root = {};
    const doc = new EventTarget();
    doc.querySelector = () => root;
    doc.body = { style: { overflow: '' } };
    globalThis.document = doc;
    globalThis.window = new EventTarget();
    try {
        let beforeVisits = 0;
        let checkVisiting = () => false;
        onPage('[data-visit-test]', (_, { onBeforeVisit, isVisiting }) => {
            checkVisiting = isVisiting;
            onBeforeVisit(() => { beforeVisits++; });
        });
        assert.equal(checkVisiting(), false);
        doc.dispatchEvent(new Event('turbo:visit'));
        assert.equal(beforeVisits, 1, 'outgoing controller must be notified immediately when visit starts');
        assert.equal(checkVisiting(), true, 'isVisiting must be true while HTTP visit is in flight');
        doc.dispatchEvent(new Event('turbo:fetch-request-error'));
        assert.equal(checkVisiting(), false, 'failed visit must reset isVisiting');
        doc.dispatchEvent(new Event('turbo:visit'));
        assert.equal(checkVisiting(), true);
        doc.dispatchEvent(new Event('turbo:before-render'));
        assert.equal(checkVisiting(), false);
    } finally {
        globalThis.document = previousDocument;
        globalThis.window = previousWindow;
    }
});

test('navigation feedback deduplicates rapid clicks, marks pending links, and handles history popstate and mobile chat view', () => {
    const doc = new EventTarget();
    const win = new EventTarget();
    win.location = { href: 'http://localhost/mensajes/10' };

    const navLink = {
        href: 'http://localhost/comunidad',
        attrs: new Map(),
        getAttribute(name) { return name === 'href' ? this.href : this.attrs.get(name); },
        setAttribute(name, val) { this.attrs.set(name, val); },
        removeAttribute(name) { this.attrs.delete(name); },
        matches: (sel) => sel.includes('.headerNav-button'),
        closest: () => navLink,
    };
    const activeConvLink = {
        href: 'http://localhost/mensajes/10',
        attrs: new Map([['aria-current', 'true']]),
        getAttribute(name) { return name === 'href' ? this.href : this.attrs.get(name); },
        setAttribute(name, val) { this.attrs.set(name, val); },
        removeAttribute(name) { this.attrs.delete(name); },
        matches: (sel) => sel.includes('.messages-conversation[aria-current="true"]'),
        closest: () => activeConvLink,
    };
    const messagingRoot = { dataset: { view: 'list' } };

    doc.querySelector = (sel) => (sel === '[data-messaging]' ? messagingRoot : null);
    doc.querySelectorAll = (sel) => {
        if (sel === '[data-nav-pending]') {
            return [navLink, activeConvLink].filter((n) => n.attrs.has('data-nav-pending'));
        }
        return [navLink, activeConvLink];
    };

    const feedback = setupNavigationFeedback(doc, win);

    // 1. Clicking already-active conversation on mobile list view switches to chat view without network reload
    const sameConvClick = Object.assign(new Event('turbo:click', { cancelable: true }), {
        detail: { url: 'http://localhost/mensajes/10' },
    });
    Object.defineProperty(sameConvClick, 'target', { value: activeConvLink });
    doc.dispatchEvent(sameConvClick);
    assert.equal(sameConvClick.defaultPrevented, true, 'must prevent redundant reload of active conversation');
    assert.equal(messagingRoot.dataset.view, 'chat', 'mobile list view must switch to chat');

    // 2. Starting a visit marks matching link with data-nav-pending="true"
    doc.dispatchEvent(Object.assign(new Event('turbo:visit'), {
        detail: { url: 'http://localhost/comunidad' },
    }));
    assert.equal(feedback.getPendingUrl(), 'http://localhost/comunidad');
    assert.equal(navLink.getAttribute('data-nav-pending'), 'true');

    // 3. Rapid second click to the same in-flight destination is deduplicated
    const dupClick = Object.assign(new Event('turbo:click', { cancelable: true }), {
        detail: { url: 'http://localhost/comunidad' },
    });
    Object.defineProperty(dupClick, 'target', { value: navLink });
    doc.dispatchEvent(dupClick);
    assert.equal(dupClick.defaultPrevented, true, 'duplicate in-flight click must be prevented');

    // 4. History popstate clears pending state immediately
    win.dispatchEvent(new Event('popstate'));
    assert.equal(feedback.getPendingUrl(), null);
    assert.equal(navLink.getAttribute('data-nav-pending'), undefined);
});
