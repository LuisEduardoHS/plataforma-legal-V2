// Safe, zero-dependency Markdown-to-DOM renderer.
// Never uses innerHTML; all text nodes are assigned via textContent.

function appendInline(parent, text) {
    const tokenPattern = /(\*\*[^*\n]+\*\*|\*[^*\n]+\*|`[^`\n]+`)/g;
    let lastIndex = 0;
    let match;

    while ((match = tokenPattern.exec(text)) !== null) {
        if (match.index > lastIndex) {
            parent.append(document.createTextNode(text.slice(lastIndex, match.index)));
        }

        const token = match[0];
        if (token.startsWith('**') && token.endsWith('**')) {
            const strong = document.createElement('strong');
            strong.textContent = token.slice(2, -2);
            parent.append(strong);
        } else if (token.startsWith('*') && token.endsWith('*')) {
            const em = document.createElement('em');
            em.textContent = token.slice(1, -1);
            parent.append(em);
        } else if (token.startsWith('`') && token.endsWith('`')) {
            const code = document.createElement('code');
            code.textContent = token.slice(1, -1);
            parent.append(code);
        }

        lastIndex = tokenPattern.lastIndex;
    }

    if (lastIndex < text.length) {
        parent.append(document.createTextNode(text.slice(lastIndex)));
    }
}

export function renderMarkdownToFragment(source) {
    const fragment = document.createDocumentFragment();
    const lines = String(source ?? '').replace(/\r\n?/g, '\n').split('\n');

    let i = 0;
    while (i < lines.length) {
        const line = lines[i];
        const trimmed = line.trim();

        if (trimmed === '') {
            i += 1;
            continue;
        }

        // Fenced code block ```
        if (trimmed.startsWith('```')) {
            const codeLines = [];
            i += 1;
            while (i < lines.length && !lines[i].trim().startsWith('```')) {
                codeLines.push(lines[i]);
                i += 1;
            }
            if (i < lines.length) i += 1;
            const pre = document.createElement('pre');
            const code = document.createElement('code');
            code.textContent = codeLines.join('\n');
            pre.append(code);
            fragment.append(pre);
            continue;
        }

        // Horizontal rule
        if (/^(-{3,}|\*{3,}|_{3,})$/.test(trimmed)) {
            fragment.append(document.createElement('hr'));
            i += 1;
            continue;
        }

        // Headings (#, ##, ###)
        const headingMatch = trimmed.match(/^(#{1,3})\s+(.+)$/);
        if (headingMatch) {
            const level = Math.min(headingMatch[1].length + 2, 5); // h3, h4, h5
            const heading = document.createElement(`h${level}`);
            appendInline(heading, headingMatch[2]);
            fragment.append(heading);
            i += 1;
            continue;
        }

        // Blockquote (> )
        if (trimmed.startsWith('>')) {
            const quote = document.createElement('blockquote');
            const quoteLines = [];
            while (i < lines.length && lines[i].trim().startsWith('>')) {
                quoteLines.push(lines[i].trim().replace(/^>\s?/, ''));
                i += 1;
            }
            const p = document.createElement('p');
            quoteLines.forEach((ql, idx) => {
                if (idx > 0) p.append(document.createElement('br'));
                appendInline(p, ql);
            });
            quote.append(p);
            fragment.append(quote);
            continue;
        }

        // Unordered list (- or *)
        if (/^[-*+]\s+/.test(trimmed)) {
            const ul = document.createElement('ul');
            while (i < lines.length && /^[-*+]\s+/.test(lines[i].trim())) {
                const li = document.createElement('li');
                appendInline(li, lines[i].trim().replace(/^[-*+]\s+/, ''));
                ul.append(li);
                i += 1;
            }
            fragment.append(ul);
            continue;
        }

        // Ordered list (1. )
        if (/^\d+\.\s+/.test(trimmed)) {
            const ol = document.createElement('ol');
            while (i < lines.length && /^\d+\.\s+/.test(lines[i].trim())) {
                const li = document.createElement('li');
                appendInline(li, lines[i].trim().replace(/^\d+\.\s+/, ''));
                ol.append(li);
                i += 1;
            }
            fragment.append(ol);
            continue;
        }

        // Paragraph
        const paraLines = [];
        while (
            i < lines.length
            && lines[i].trim() !== ''
            && !lines[i].trim().startsWith('```')
            && !lines[i].trim().startsWith('>')
            && !/^(#{1,3})\s+/.test(lines[i].trim())
            && !/^[-*+]\s+/.test(lines[i].trim())
            && !/^\d+\.\s+/.test(lines[i].trim())
            && !/^(-{3,}|\*{3,}|_{3,})$/.test(lines[i].trim())
        ) {
            paraLines.push(lines[i]);
            i += 1;
        }

        const p = document.createElement('p');
        paraLines.forEach((pl, idx) => {
            if (idx > 0) p.append(document.createElement('br'));
            appendInline(p, pl);
        });
        fragment.append(p);
    }

    return fragment;
}
