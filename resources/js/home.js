import { writeHandoff } from './ui/handoff.js';

const root = document.querySelector('[data-home]');
if (root) {
    const $ = (selector) => root.querySelector(selector);
    const data = JSON.parse($('[data-home-data]').textContent);
    const question = $('[data-home-question]');
    const dialog = $('[data-home-dialog]');
    let opener;
    let previousOverflow;

    function forward(target, value, url) {
        try {
            writeHandoff(target, value);
            location.assign(url);
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
            const link = document.createElement('a'); link.href = root.querySelector('.home-section-heading a').href; link.className = 'home-action'; link.textContent = 'Explorar recursos'; box.append(link);
        } else {
            $('#home-detail-title').textContent = data.professional.name;
            paragraph(data.professional.specialty + ' · ' + data.professional.location);
            paragraph('Perfil de referencia. El contacto y la verificación se conectarán con el directorio de profesionales.');
            const link = document.createElement('a');
            link.href = root.querySelector('.home-shortcuts a:last-child').href;
            link.className = 'home-action'; link.textContent = 'Explorar profesionales'; box.append(link);
        }
        dialog.showModal();
    }
    dialog.addEventListener('close', () => {
        document.body.style.overflow = previousOverflow;
        opener?.focus({ preventScroll: true });
    });
    root.addEventListener('click', (event) => {
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
        if (button.matches('[data-bookmark]')) {
            const saved = button.getAttribute('aria-pressed') !== 'true';
            button.setAttribute('aria-pressed', String(saved));
            $('[data-home-status]').textContent = saved ? 'Guardado temporalmente en esta página.' : 'Retirado de los guardados de esta página.';
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
        if (button.matches('[data-open-professional]')) showDetail('professional', button);
        if (button.matches('[data-close-dialog]')) dialog.close();
    });
    $('#home-pending-title').tabIndex = -1;
}