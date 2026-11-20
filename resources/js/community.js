import { onPage } from './ui/page.js';
import { takeHandoff } from './ui/handoff.js';
import { validateCommunityFiles } from './community/files.js';

onPage('[data-community]', (root, { listen, onCleanup }) => {
    const errors = root.querySelector('[data-community-errors]');
    if (errors && (document.activeElement === document.body || document.activeElement?.id === 'main-content')) errors.focus();
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
        let previewUrls = [];
        let selectedFiles = [];
        const clearPreviewUrls = () => {
            previewUrls.forEach((url) => URL.revokeObjectURL(url));
            previewUrls = [];
        };
        onCleanup(clearPreviewUrls);
        const syncInputFiles = () => {
            const dt = new DataTransfer();
            selectedFiles.forEach((file) => dt.items.add(file));
            input.files = dt.files;
        };
        const validate = () => {
            const files = selectedFiles;
            const errors = validateCommunityFiles(files, JSON.parse(form.dataset.uploadRules), form.querySelectorAll('[name="attachment_ids[]"]:checked').length);
            const error = form.querySelector('[data-upload-error]');
            error.textContent = errors.join(' ');
            error.hidden = errors.length === 0;
            input.setCustomValidity(errors.length ? 'Revisa los archivos seleccionados.' : '');
            const list = form.querySelector('[data-upload-list]');
            clearPreviewUrls();
            const mediaCount = files.filter((file) => file.type.startsWith('image/') || file.type.startsWith('video/')).length;
            list.replaceChildren(...files.map((file, index) => {
                const isMedia = file.type.startsWith('image/') || file.type.startsWith('video/');
                const item = document.createElement('li');

                if (isMedia) {
                    const spanFull = mediaCount === 1 || (mediaCount > 2 && mediaCount % 2 === 1 && index === 0);
                    item.className = `relative overflow-hidden rounded-xl border border-black/10 bg-[var(--community-soft)] dark:border-white/10 ${spanFull ? 'sm:col-span-2' : ''}`;

                    const url = URL.createObjectURL(file);
                    previewUrls.push(url);

                    if (file.type.startsWith('image/')) {
                        const img = document.createElement('img');
                        img.src = url;
                        img.alt = file.name;
                        img.className = `block w-full ${mediaCount === 1 ? 'max-h-80 object-contain' : 'h-48 object-cover'}`;
                        item.appendChild(img);
                    } else {
                        const video = document.createElement('video');
                        video.src = url;
                        video.controls = true;
                        video.muted = true;
                        video.playsInline = true;
                        video.className = `block w-full bg-black/90 ${mediaCount === 1 ? 'max-h-80' : 'h-48'}`;
                        item.appendChild(video);
                    }

                    const caption = document.createElement('div');
                    caption.className = 'flex items-center justify-between gap-2 border-t border-black/8 px-3 py-2 text-[11px] text-light-text dark:border-white/10 dark:text-light-text-dark';
                    const name = document.createElement('span');
                    name.className = 'truncate font-medium text-text dark:text-text-dark';
                    name.textContent = file.name;
                    const size = document.createElement('span');
                    size.className = 'shrink-0';
                    size.textContent = `${Math.max(1, Math.ceil(file.size / 1024))} KiB`;
                    caption.append(name, size);
                    item.appendChild(caption);

                    const remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'absolute top-2 right-2 inline-flex size-7 items-center justify-center rounded-full bg-black/60 text-sm font-semibold text-white backdrop-blur-sm transition-colors hover:bg-black/80';
                    remove.setAttribute('aria-label', `Quitar ${file.name}`);
                    remove.title = `Quitar ${file.name}`;
                    remove.textContent = '×';
                    remove.addEventListener('click', () => {
                        selectedFiles = selectedFiles.filter((_, otherIndex) => otherIndex !== index);
                        syncInputFiles();
                        validate();
                    });
                    item.appendChild(remove);

                    return item;
                }

                item.className = 'community-preview sm:col-span-2';
                const badge = document.createElement('span');
                badge.className = 'community-preview-document !h-12 !w-12 text-xs font-semibold text-accent dark:text-accent-text-dark';
                badge.setAttribute('aria-hidden', 'true');
                const ext = file.name.includes('.') ? file.name.split('.').pop().toUpperCase() : 'DOC';
                badge.textContent = ext.slice(0, 4);
                item.appendChild(badge);

                const info = document.createElement('div');
                info.className = 'community-preview-info';
                const name = document.createElement('strong');
                name.textContent = file.name;
                const meta = document.createElement('span');
                meta.textContent = `${Math.max(1, Math.ceil(file.size / 1024))} KiB · Listo para subir`;
                info.append(name, meta);
                item.appendChild(info);

                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'community-icon-button !min-w-8 !min-h-8 !rounded-full shrink-0 text-light-text hover:text-accent dark:text-light-text-dark dark:hover:text-accent-text-dark';
                remove.setAttribute('aria-label', `Quitar ${file.name}`);
                remove.title = `Quitar ${file.name}`;
                remove.textContent = '×';
                remove.addEventListener('click', () => {
                    selectedFiles = selectedFiles.filter((_, otherIndex) => otherIndex !== index);
                    syncInputFiles();
                    validate();
                });
                item.appendChild(remove);

                return item;
            }));
        };
        listen(input, 'change', () => {
            const incoming = [...input.files];
            incoming.forEach((file) => {
                const duplicate = selectedFiles.some((existing) => existing.name === file.name && existing.size === file.size && existing.lastModified === file.lastModified);
                if (!duplicate) selectedFiles.push(file);
            });
            syncInputFiles();
            validate();
        });
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
