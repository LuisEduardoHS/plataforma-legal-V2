import test from 'node:test';
import assert from 'node:assert/strict';
import { renderMarkdownToFragment } from '../../resources/js/lexa/markdown.js';

function makeNode(tag) {
    const node = {
        tagName: tag.toUpperCase(),
        children: [],
        _text: '',
        append(...items) {
            for (const item of items) this.children.push(item);
        },
        set textContent(value) {
            this._text = String(value);
            this.children = [];
        },
        get textContent() {
            if (this.children.length === 0) return this._text;
            return this.children.map((c) => c.textContent).join('');
        },
        findAll(predicate, out = []) {
            for (const child of this.children) {
                if (predicate(child)) out.push(child);
                if (child.findAll) child.findAll(predicate, out);
            }
            return out;
        },
    };
    return node;
}

globalThis.document = {
    createElement: (tag) => makeNode(tag),
    createDocumentFragment: () => makeNode('#fragment'),
    createTextNode: (text) => ({ tagName: '#text', textContent: String(text) }),
};

test('renderMarkdownToFragment renders headings, lists, emphasis, code, and blockquotes', () => {
    const md = [
        '## Orientación inicial',
        '',
        'Revisa **estos puntos** con *cuidado* usando `LEXA`:',
        '',
        '- Primer elemento',
        '- Segundo elemento',
        '',
        '1. Paso uno',
        '2. Paso dos',
        '',
        '> Nota importante.',
        '',
        '```txt',
        'Cláusula 1',
        '```',
    ].join('\n');

    const root = makeNode('div');
    root.append(renderMarkdownToFragment(md));

    assert.equal(root.findAll((n) => n.tagName === 'H4')[0]?.textContent, 'Orientación inicial');
    assert.equal(root.findAll((n) => n.tagName === 'STRONG')[0]?.textContent, 'estos puntos');
    assert.equal(root.findAll((n) => n.tagName === 'EM')[0]?.textContent, 'cuidado');
    assert.equal(root.findAll((n) => n.tagName === 'CODE')[0]?.textContent, 'LEXA');
    assert.equal(root.findAll((n) => n.tagName === 'UL')[0]?.children.length, 2);
    assert.equal(root.findAll((n) => n.tagName === 'OL')[0]?.children.length, 2);
    assert.equal(root.findAll((n) => n.tagName === 'BLOCKQUOTE')[0]?.textContent, 'Nota importante.');
    assert.equal(root.findAll((n) => n.tagName === 'PRE')[0]?.textContent, 'Cláusula 1');
});

test('renderMarkdownToFragment escapes raw HTML and prevents XSS', () => {
    const root = makeNode('div');
    root.append(renderMarkdownToFragment('<script>alert(1)</script> y **<img src=x onerror=alert(2)>**'));

    assert.equal(root.findAll((n) => n.tagName === 'SCRIPT').length, 0);
    assert.equal(root.findAll((n) => n.tagName === 'IMG').length, 0);
    assert.ok(root.textContent.includes('<script>alert(1)</script>'));
    assert.equal(root.findAll((n) => n.tagName === 'STRONG')[0]?.textContent, '<img src=x onerror=alert(2)>');
});
