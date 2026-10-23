import { onPage } from './ui/page.js';
import { library } from './saved/library.js';
import { normalize, selectSaved, STORAGE_KEY } from './saved/store.js';

onPage('[data-help]', (root) => {
    const search = root.querySelector('[data-help-search]');
    const questions = [...root.querySelectorAll('[data-help-question]')];
    let category = 'all';
    function render() {
        const words = normalize(search.value).split(/\s+/).filter(Boolean);
        let count = 0;
        questions.forEach((item) => {
            item.hidden = (category !== 'all' && item.dataset.category !== category)
                || !words.every((word) => normalize(item.textContent).includes(word));
            if (item.hidden) item.open = false; else count++;
        });
        root.querySelector('[data-help-count]').textContent = `${count} ${count === 1 ? 'pregunta' : 'preguntas'}`;
        root.querySelector('[data-help-empty]').hidden = count > 0;
        root.querySelector('[data-help-guides]').hidden = Boolean(search.value.trim()) || category !== 'all';
        root.querySelectorAll('[data-help-category]').forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.helpCategory === category)));
    }
    search.addEventListener('input', render);
    root.addEventListener('click', (event) => {
        const filter = event.target.closest('[data-help-category]');
        if (filter) { category = filter.dataset.helpCategory; render(); }
        if (event.target.closest('[data-help-reset]')) { search.value = ''; category = 'all'; render(); search.focus(); }
    });
});

onPage('[data-saved]', (root, { listen }) => {
    const $ = (selector, scope = root) => scope.querySelector(selector);
    const search = $('[data-saved-search]');
    const list = $('[data-saved-list]');
    const error = $('[data-saved-error]');
    let kind = 'all';
    let removed;
    function reportError() {
        error.textContent = 'No pudimos actualizar tus guardados. Comprueba que el navegador permita almacenar datos del sitio e inténtalo de nuevo.';
        error.hidden = false;
    }
    function render() {
        const items = library.read();
        const selected = selectSaved(items, { kind, query: search.value, order: $('[data-saved-order]').value });
        list.replaceChildren();
        for (const item of selected) {
            const card = $('[data-saved-template]').content.firstElementChild.cloneNode(true);
            card.dataset.key = `${item.kind}:${item.id}`;
            $('[data-item-type]', card).textContent = item.kind === 'post' ? 'Publicación' : 'Artículo';
            $('[data-item-category]', card).textContent = item.category || 'General';
            const link = $('[data-item-link]', card); link.textContent = item.title; link.href = item.href;
            $('[data-item-summary]', card).textContent = item.summary;
            $('[data-item-author]', card).textContent = item.author || (item.kind === 'article' ? 'Recursos de la plataforma' : 'Comunidad');
            const time = $('[data-item-time]', card);
            time.dateTime = new Date(item.savedAt).toISOString();
            time.textContent = 'Guardado el ' + new Intl.DateTimeFormat('es-MX', { day: 'numeric', month: 'short' }).format(item.savedAt);
            const remove = $('[data-remove-saved]', card);
            remove.dataset.id = item.id; remove.dataset.kind = item.kind;
            remove.setAttribute('aria-label', 'Quitar de Guardados: ' + item.title);
            list.append(card);
        }
        $('[data-saved-total]').textContent = items.length;
        $('[data-count-post]').textContent = items.filter((item) => item.kind === 'post').length;
        $('[data-count-article]').textContent = items.filter((item) => item.kind === 'article').length;
        $('[data-saved-result]').textContent = `${selected.length} ${selected.length === 1 ? 'guardado' : 'guardados'}`;
        $('[data-saved-empty]').hidden = items.length > 0;
        $('[data-saved-no-results]').hidden = !items.length || selected.length > 0;
        root.querySelectorAll('[data-saved-kind]').forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.savedKind === kind)));
    }
    search.addEventListener('input', render);
    $('[data-saved-order]').addEventListener('change', render);
    listen(window, 'saved:change', render);
    listen(window, 'storage', (event) => { if (event.key === STORAGE_KEY || event.key === null) render(); });
    root.addEventListener('click', (event) => {
        const filter = event.target.closest('[data-saved-kind]');
        if (filter) { kind = filter.dataset.savedKind; render(); }
        if (event.target.closest('[data-saved-reset]')) { search.value = ''; kind = 'all'; render(); search.focus(); }
        const remove = event.target.closest('[data-remove-saved]');
        if (remove) {
            const item = library.read().find((item) => item.id === remove.dataset.id && item.kind === remove.dataset.kind);
            if (!item) return;
            try {
                library.remove(item.kind, item.id); removed = item;
                error.hidden = true; $('[data-saved-undo]').hidden = false;
                $('[data-undo]').focus();
            } catch { reportError(); }
        }
        if (event.target.closest('[data-undo]') && removed) {
            try {
                library.add(removed); error.hidden = true; $('[data-saved-undo]').hidden = true;
                const restored = [...list.children].find((card) => card.dataset.key === `${removed.kind}:${removed.id}`);
                (restored?.querySelector('a') || search).focus(); removed = null;
            } catch { reportError(); }
        }
    });
    render();
});
