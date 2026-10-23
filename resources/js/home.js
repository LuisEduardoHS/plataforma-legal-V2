import { onPage, navigate } from './ui/navigation.js';
import { writeHandoff } from './ui/handoff.js';
import { library } from './saved/library.js';


onPage('[data-home]', (root, { listen, onCleanup }) => {
    const $ = (selector) => root.querySelector(selector);
    const data = JSON.parse($('[data-home-data]').textContent);
    const question = $('[data-home-question]');
    const dialog = $('[data-home-dialog]');
    let opener;
    let previousOverflow;
    function bookmarkRecord(button) {
        const post = data.feed.find((post) => post.id === button.closest('[data-home-post]')?.dataset.homePost);
        return post ? { id: post.id, kind: 'post', title: post.title, category: post.category, summary: post.body, author: post.author, href: '/comunidad#post-' + post.id }
            : { id: 'inicio-finiquito', kind: 'article', ...data.article, href: '/inicio#lectura-finiquito' };
    }
    function syncBookmarks() {
        root.querySelectorAll('[data-bookmark]').forEach((button) => {
            const record = bookmarkRecord(button);
            const saved = library.has(record.kind, record.id);
            button.setAttribute('aria-pressed', String(saved));
            button.setAttribute('aria-label', (saved ? 'Quitar de Guardados: ' : 'Guardar: ') + record.title);
            button.title = saved ? 'Quitar de Guardados' : 'Guardar';
        });
    }
    listen(window, 'saved:change', syncBookmarks);
    listen(window, 'storage', syncBookmarks);
    syncBookmarks();

    function forward(target, value, url) {
        try {
            writeHandoff(target, value);
            navigate(url);
        } catch {
            $('[data-home-error]').hidden = false;
            $('[data-home-error]').textContent = 'No pudimos trasladar el borrador. Conserva el texto y vuelve a intentarlo.';
        }
    }
    function toLexa(context = false) {
        forward('lexa', { text: question.value.trim(), mode: $('[data-home-mode]').value, context }, root.querySelector('[data-add-context]').href);
    }
    question.addEventListener('input', () => {
        $('[data-home-send]').disabled = !question.value.trim();
        question.style.height = 'auto';
        question.style.height = Math.min(question.scrollHeight, 180) + 'px';
    });
    question.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            if (question.value.trim()) toLexa();
        }
    });
    $('[data-home-composer]').addEventListener('submit', (event) => {
        event.preventDefault();
        if (question.value.trim()) toLexa();
    });
    function showDetail(kind, trigger) {
        opener = trigger;
        previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        const box = $('[data-home-detail]');
        box.replaceChildren();
        const paragraph = (text) => { const p = document.createElement('p'); p.textContent = text; box.append(p); };
        if (kind === 'article') {
            $('#home-detail-title').textContent = data.article.title;
            paragraph('Lectura de ejemplo · ' + data.article.category + ' · ' + data.article.duration);
            paragraph('Antes de una consulta profesional, puede ayudarte ordenar lo sucedido, los documentos disponibles y las dudas que quieres aclarar.');
            const list = document.createElement('ul');
            for (const text of ['Escribe un resumen de tu situación con fechas y hechos.', 'Anota qué información tienes disponible y qué te gustaría entender.', 'Prepara tus preguntas para una revisión individual con un profesional.']) {
                const li = document.createElement('li'); li.textContent = text; list.append(li);
            }
            box.append(list);
            const link = document.createElement('a'); link.href = root.dataset.resourcesUrl; link.className = 'home-action'; link.textContent = 'Explorar recursos'; box.append(link);
        }
        dialog.showModal();
    }
    dialog.addEventListener('close', () => {
        document.body.style.overflow = previousOverflow;
        opener?.focus({ preventScroll: true });
    });
    root.addEventListener('click', (event) => {
        if (event.target.closest('[data-home-share]')) {
            event.preventDefault();
            forward('community', { text: '' }, event.target.closest('[data-home-share]').href);
            return;
        }
        const continuation = event.target.closest('[data-continue]');
        if (continuation) {
            const item = data.continue.find((item) => item.id === continuation.dataset.continue);
            if (item?.kind === 'lexa') {
                event.preventDefault();
                forward('lexa', { sessionId: item.session }, continuation.href);
            }
            if (item?.kind === 'draft') {
                event.preventDefault();
                forward('community', { text: item.draft, category: 'Arrendamiento' }, continuation.href);
            }
        }
        if (event.target.closest('[data-add-context]')) { event.preventDefault(); toLexa(true); }
        const button = event.target.closest('button');
        if (!button) return;
        if (button.matches('[data-home-prompt]')) {
            question.value = (question.value.trim() ? question.value.trim() + '\n' : '') + button.dataset.homePrompt;
            question.value = question.value.slice(0, question.maxLength);
            question.dispatchEvent(new Event('input', { bubbles: true }));
            question.focus();
            $('[data-home-status]').textContent = 'Sugerencia añadida. Puedes editarla antes de continuar.';
        }
        if (button.matches('[data-bookmark]')) {
            try {
                const saved = library.toggle(bookmarkRecord(button));
                $('[data-home-status]').textContent = saved ? 'Añadido a Guardados en este navegador.' : 'Retirado de Guardados.';
            } catch { $('[data-home-status]').textContent = 'No se pudo guardar. Comprueba que el navegador permita almacenar datos del sitio.'; }
        }
        if (button.matches('[data-dismiss-pending]')) {
            button.closest('[data-pending-item]').remove();
            const count = root.querySelectorAll('[data-pending-item]').length;
            $('[data-pending-count]').textContent = count;
            $('[data-pending-empty]').hidden = count > 0;
            $('[data-home-status]').textContent = 'Recordatorio ocultado en esta página.';
            (root.querySelector('[data-dismiss-pending]') || $('#home-pending-title')).focus();
        }
        if (button.matches('[data-open-article]')) showDetail('article', button);

        if (button.matches('[data-close-dialog]')) dialog.close();
    });
    $('#home-pending-title').tabIndex = -1;
    if (location.hash === '#lectura-finiquito') showDetail('article', $('[data-open-article]'));
});
