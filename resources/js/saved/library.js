import { onPage } from '../ui/page.js';
import { savedResponse } from './store.js';

onPage('#main-content', (root, { listen, onCleanup }) => {
    const pending = new Set();
    const timers = new WeakMap();
    const activeMessages = new Set();
    const requests = new AbortController();
    const showMessage = (message, text, duration = 3000) => {
        if (!message) return;
        const previous = timers.get(message);
        if (previous) clearTimeout(previous);
        message.textContent = text;
        message.hidden = false;
        activeMessages.add(message);
        timers.set(message, setTimeout(() => {
            message.hidden = true;
            message.textContent = '';
            timers.delete(message);
            activeMessages.delete(message);
        }, duration));
    };
    onCleanup(() => {
        requests.abort();
        activeMessages.forEach((message) => {
            const timer = timers.get(message);
            if (timer) clearTimeout(timer);
        });
        activeMessages.clear();
    });
    listen(root, 'submit', async (event) => {
        const form = event.target.closest('[data-save-form]');
        if (!form) return;
        event.preventDefault();
        const key = `${form.dataset.saveType}:${form.dataset.saveId}`;
        if (pending.has(key)) return;
        pending.add(key);
        const button = form.querySelector('button');
        button.disabled = true;
        const message = form.parentElement.querySelector('[data-save-message]');
        try {
            const response = await fetch(form.action, {
                method: 'POST', body: new FormData(form), credentials: 'same-origin', signal: requests.signal,
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await response.json().catch(() => null);
            const result = savedResponse(response.ok, response.status, data);
            root.querySelectorAll('[data-save-form]').forEach((other) => {
                if (other.dataset.saveType !== form.dataset.saveType || other.dataset.saveId !== form.dataset.saveId) return;
                other.querySelector('[name="_method"]').value = result.saved ? 'DELETE' : 'PUT';
                const control = other.querySelector('button');
                control.setAttribute('aria-pressed', String(result.saved));
                control.setAttribute('aria-label', result.saved ? 'Quitar de Guardados' : 'Guardar contenido');
                control.title = control.getAttribute('aria-label');
                control.classList.toggle('is-saved', result.saved);
            });
            showMessage(message, result.message);
            const card = form.closest('[data-library-item]');
            if (card && !result.saved) {
                // Refresh the server page so counts, cursors and revoked visibility
                // are resolved again; never retain a local copy of the library.
                window.location.reload();
            }
        } catch (error) {
            if (error.name === 'AbortError') return;
            showMessage(message, error instanceof TypeError ? 'No se pudo conectar. Inténtalo nuevamente.' : error.message, 4000);
        } finally {
            pending.delete(key);
            button.disabled = false;
        }
    });
});
