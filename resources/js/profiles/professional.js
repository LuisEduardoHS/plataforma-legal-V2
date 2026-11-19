import { setupDialog } from '../ui/dialog.js';

// The server decides materiality and supplies only categories and an authenticated proposal.
export function setupProfessionalEditor(root, lifecycle) {
    const { listen, onCleanup } = lifecycle;
    const form = root.querySelector('[data-professional-edit]');
    const confirmation = root.querySelector('[data-professional-confirmation]');
    const dialog = confirmation?.querySelector('dialog');
    if (!form || typeof dialog?.showModal !== 'function') return;
    const feedback = form.querySelector('[data-professional-feedback]');
    const final = confirmation.querySelector('[data-professional-final]');
    const trigger = confirmation.querySelector('[data-dialog-open]');
    let busy = false, disposed = false, finalSent = false, opener, controller;
    setupDialog(confirmation, lifecycle);
    listen(dialog, 'close', () => { if (!disposed && opener?.isConnected) opener.focus(); });
    listen(final, 'submit', event => {
        if (finalSent) { event.preventDefault(); return; }
        finalSent = true;
        final.querySelector('button').disabled = true;
        form.querySelectorAll('button[type="submit"],button:not([type])').forEach(button => { button.disabled = true; });
    });
    listen(form, 'submit', async event => {
        if (event.defaultPrevented) return; // Municipality loading owns its queued submission.
        event.preventDefault();
        if (busy) return;
        busy = true;
        opener = event.submitter;
        const body = new FormData(form);
        if (opener?.name) body.append(opener.name, opener.value);
        controller = new AbortController();
        feedback.textContent = 'Revisando los cambios…';
        try {
            const response = await fetch(form.action, {
                method: 'POST', body, credentials: 'same-origin',
                headers: { Accept: 'application/json' }, signal: controller.signal,
            });
            const data = await response.json();
            if (disposed) return;
            if (!response.ok) {
                feedback.textContent = response.status === 409
                    ? 'Los datos o el expediente cambiaron. Abre de nuevo el editor antes de guardar.'
                    : (Object.values(data.errors ?? {}).flat().filter(message => typeof message === 'string').join(' ') || 'No se completó el guardado. Revisa el formulario e inténtalo de nuevo.');
                feedback.setAttribute('tabindex', '-1');
                feedback.focus();
                return;
            }
            if (data.redirect) {
                // The server returns the fixed profile route, never a client-selected destination.
                window.location.assign(data.redirect);
                return;
            }
            if (!Array.isArray(data.categories) || typeof data.pending_change !== 'string' || typeof data.notice !== 'string') throw new Error('Invalid review');
            confirmation.querySelector('[data-professional-description]').textContent = data.notice;
            const list = confirmation.querySelector('[data-professional-categories]');
            list.replaceChildren(...data.categories.map(category => {
                const item = document.createElement('li');
                item.textContent = category;
                return item;
            }));
            final.querySelector('[name="pending_change"]').value = data.pending_change;
            confirmation.hidden = false;
            feedback.textContent = 'Revisión preparada. Confirma o cancela; todavía no se ha guardado.';
            trigger.click();
        } catch (error) {
            if (!disposed && error.name !== 'AbortError') feedback.textContent = 'No pudimos confirmar la respuesta. Revisa tus datos antes de volver a enviar; no reintentaremos automáticamente.';
        } finally {
            busy = false;
        }
    });
    // An edit after Cancel must obtain a new server review, never reuse the old proposal.
    listen(form, 'input', () => {
        final.querySelector('[name="pending_change"]').value = '';
        confirmation.hidden = true;
    });
    onCleanup(() => {
        disposed = true;
        controller?.abort();
        final.querySelector('[name="pending_change"]').value = '';
        confirmation.hidden = true;
    });
}
