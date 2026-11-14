import { onPage } from './ui/page.js';
import { takeHandoff } from './ui/handoff.js';
import { validateCommunityFiles } from './community/files.js';

onPage('[data-community]', (root, { listen, onCleanup }) => {
    const dialog = root.querySelector('[data-create-dialog]');
    let opener;
    const overflow = document.body.style.overflow;
    if (dialog) {
        listen(root, 'click', (event) => {
            const trigger = event.target.closest('[data-open-create]');
            if (trigger && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey && event.button === 0) {
                event.preventDefault();
                opener = trigger;
                dialog.showModal();
                document.body.style.overflow = 'hidden';
                dialog.querySelector('textarea').focus();
            }
            if (event.target.closest('[data-close-create]')) dialog.close();
        });
        listen(dialog, 'close', () => { document.body.style.overflow = overflow; opener?.focus(); });
        onCleanup(() => { if (dialog.open) dialog.close(); document.body.style.overflow = overflow; });
    }
    root.querySelectorAll('[data-post-form]').forEach((form) => {
        const draft = form.querySelector('textarea');
        const count = form.querySelector('[data-draft-count]');
        const updateCount = () => { count.textContent = `${draft.value.length} / ${draft.maxLength}`; };
        listen(draft, 'input', updateCount);
        updateCount();
        const input = form.querySelector('[data-community-uploads]');
        if (!input) return;
        const validate = () => {
            const files = [...input.files];
            const errors = validateCommunityFiles(files, JSON.parse(form.dataset.uploadRules), form.querySelectorAll('[name="attachment_ids[]"]:checked').length);
            const error = form.querySelector('[data-upload-error]');
            error.textContent = errors.join(' ');
            error.hidden = errors.length === 0;
            input.setCustomValidity(errors.length ? 'Revisa los archivos seleccionados.' : '');
            const list = form.querySelector('[data-upload-list]');
            list.replaceChildren(...files.map((file) => {
                const item = document.createElement('li');
                item.className = 'community-preview';
                item.textContent = `${file.name} · ${Math.max(1, Math.ceil(file.size / 1024))} KiB · Pendiente de carga`;
                return item;
            }));
        };
        listen(input, 'change', validate);
        form.querySelectorAll('[name="attachment_ids[]"]').forEach((checkbox) => listen(checkbox, 'change', validate));
    });
    root.querySelectorAll('form').forEach((form) => {
        if (form.method.toLowerCase() !== 'post') return;
        listen(form, 'submit', (event) => {
            if (form.dataset.pending === 'true') { event.preventDefault(); return; }
            form.dataset.pending = 'true';
            // Keep the submitter enabled so its validated intent is submitted.
            event.submitter?.setAttribute('aria-disabled', 'true');
            const status = form.querySelector('[data-submit-status]');
            if (status) status.textContent = 'Guardando…';
        });
    });
    listen(root, 'click', async (event) => {
        const button = event.target.closest('[data-share-post]');
        if (!button) return;
        const status = button.closest('article').querySelector('[data-action-status]');
        try { await navigator.clipboard.writeText(button.dataset.sharePost); status.textContent = 'Enlace copiado.'; }
        catch { status.textContent = 'No pudimos copiarlo. Abre la publicación para usar el enlace de la barra de direcciones.'; }
    });
    const incomingForm = root.querySelector('[data-post-form]');
    const incoming = incomingForm ? takeHandoff('community') : null;
    if (incoming && typeof incoming.text === 'string') {
        const form = incomingForm;
        if (form) {
            const draft = form.querySelector('textarea');
            draft.value = incoming.text.slice(0, draft.maxLength);
            draft.dispatchEvent(new Event('input', { bubbles: true }));
            if (dialog) root.querySelector('[data-open-create]')?.click();
        }
    }
});
