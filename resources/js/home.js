import { onPage, navigate } from './ui/navigation.js';
import { writeHandoff } from './ui/handoff.js';

onPage('[data-home]', (root, { listen }) => {
    const $ = (selector) => root.querySelector(selector);
    const question = $('[data-home-question]');
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
    listen(question, 'input', () => {
        $('[data-home-send]').disabled = !question.value.trim();
        question.style.height = 'auto';
        question.style.height = Math.min(question.scrollHeight, 180) + 'px';
    });
    listen(question, 'keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            if (question.value.trim()) toLexa();
        }
    });
    listen($('[data-home-composer]'), 'submit', (event) => {
        event.preventDefault();
        if (question.value.trim()) toLexa();
    });
    listen(root, 'click', (event) => {
        if (event.target.closest('[data-home-share]')) {
            event.preventDefault();
            forward('community', { text: '' }, event.target.closest('[data-home-share]').href);
            return;
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
    });
});
