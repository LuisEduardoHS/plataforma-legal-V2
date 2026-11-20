import test from 'node:test';
import assert from 'node:assert/strict';
import { renderLegalCitations } from '../../resources/js/lexa/citations.js';

function node(tag) {
    return { tagName: tag, children: [], attrs: {}, _text: '',
        append(...items) { this.children.push(...items); },
        setAttribute(key, value) { this.attrs[key] = value; },
        set textContent(value) { this._text = String(value); this.children = []; },
        get textContent() { return this._text + this.children.map(item => item.textContent).join(' '); },
        set innerHTML(_) { throw new Error('Unsafe HTML'); },
    };
}
globalThis.document = { createElement: node };
const source = { instrument: '<img src=x onerror=alert(1)>', article_ref: 'Artículo 2', part: 1,
    source_url: 'https://www.diputados.gob.mx/LeyesBiblio/index.htm', jurisdiction_scope: 'state', state_name: 'Entidad sintética', version_key: 'v1', validity_confirmed: false };

test('citations use text nodes, official links, jurisdiction and a validity warning', () => {
    const result = renderLegalCitations([source], { state_id: 1, cited_count: 1 });
    const item = result.children[1].children[0];
    assert.equal(result.attrs['aria-label'], 'Fuentes documentales de esta respuesta');
    assert.equal(item.children[0].href, source.source_url);
    assert.equal(item.children[0].rel, 'noopener noreferrer');
    assert.match(item.children[0].textContent, /<img src=x/);
    assert.match(item.children[1].textContent, /Entidad sintética.*v1/);
    assert.match(item.children[2].textContent, /vigencia no confirmada/);
});

test('invented or executable links are not rendered', () => {
    for (const source_url of ['javascript:alert(1)', 'https://evil.test/legal', 'https://www.diputados.gob.mx.evil.test/', 'https://secret@www.diputados.gob.mx/']) {
        assert.equal(renderLegalCitations([{ ...source, source_url }]).children[1].children.length, 0);
    }
});

test('missing sources and historical responses are explicit and need no storage', () => {
    assert.match(renderLegalCitations([], { cited_count: 0 }).textContent, /sin fundamento documental suficiente/);
    assert.match(renderLegalCitations([], null).textContent, /sin trazabilidad documental/);
    assert.match(renderLegalCitations([{ ...source, validity_confirmed: true }], { cited_count: 1 }).textContent, /no implica confirmación de vigencia actual/);
});
