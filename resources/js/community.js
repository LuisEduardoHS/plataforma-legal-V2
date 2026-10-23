import { takeHandoff } from './ui/handoff.js';

const community = document.querySelector('[data-community]');

if (community) {
    const $ = (selector) => community.querySelector(selector);
    const $$ = (selector) => [...community.querySelectorAll(selector)];
    const create = $('[data-create-dialog]');
    const detail = $('[data-community-dialog]');
    const draft = $('#community-draft');
    const category = $('#community-category');
    const discard = $('[data-discard]');
    const previews = $('[data-previews]');
    const error = $('[data-file-error]');
    const feed = $('#community-feed');
    const posts = $$('[data-post]');
    const tabs = $$('[data-filter]');
    const limits = { image: 10, video: 50, document: 20 };
    const types = {
        image: { jpg: ['image/jpeg'], jpeg: ['image/jpeg'], png: ['image/png'], webp: ['image/webp'] },
        video: { mp4: ['video/mp4'], webm: ['video/webm'] },
        document: { pdf: ['application/pdf'], docx: ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'] },
    };
    const attachments = [];
    const icons = Object.fromEntries($$('[data-upload-kind]').map((label) => [
        label.dataset.uploadKind, label.querySelector('svg').cloneNode(true),
    ]));
    const closeIcon = create.querySelector('[data-close-create] svg').cloneNode(true);
    let opener;
    let previousOverflow;
    let activeFilter = 'para-ti';
    let activeTopic = '';
    let sequence = 0;

    function open(dialog, trigger) {
        opener = trigger;
        previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        dialog.showModal();
    }
    for (const dialog of [create, detail]) {
        dialog.addEventListener('close', () => {
            document.body.style.overflow = previousOverflow;
            opener?.focus({ preventScroll: true });
        });
    }

    function applyFilter(filter = activeFilter, updateUrl = true) {
        activeFilter = tabs.some((tab) => tab.dataset.filter === filter) ? filter : 'para-ti';
        tabs.forEach((tab) => {
            const selected = tab.dataset.filter === activeFilter;
            tab.setAttribute('aria-selected', String(selected));
            tab.tabIndex = selected ? 0 : -1;
        });
        $('.community-tabs').style.setProperty('--tab-index', tabs.findIndex((tab) => tab.dataset.filter === activeFilter));
        feed.setAttribute('aria-labelledby', 'tab-' + activeFilter);
        const ordered = activeFilter === 'recientes'
            ? [...posts].sort((a, b) => b.dataset.date.localeCompare(a.dataset.date)) : posts;
        let visible = 0;
        for (const post of ordered) {
            post.hidden = (activeFilter === 'sin-respuesta' && Number(post.dataset.replies) !== 0)
                || (activeTopic !== '' && post.dataset.category !== activeTopic);
            if (!post.hidden) visible++;
            feed.append(post);
        }
        const empty = $('[data-feed-empty]');
        if (empty) { empty.hidden = visible > 0; feed.append(empty); }
        $$('[data-topic]').forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.topic === activeTopic)));
        $('[data-filter-status]').textContent = visible + (visible === 1 ? ' publicación' : ' publicaciones') + (activeTopic ? ' de ' + activeTopic : '');
        if (updateUrl) {
            const url = new URL(location.href);
            url.searchParams.set('filtro', activeFilter);
            if (activeTopic) url.searchParams.set('tema', activeTopic); else url.searchParams.delete('tema');
            history.replaceState(null, '', url);
        }
    }
    function readUrl() {
        const url = new URL(location.href);
        activeTopic = url.searchParams.get('tema') || '';
        applyFilter(url.searchParams.get('filtro') || 'para-ti', false);
    }
    readUrl();
    window.addEventListener('popstate', readUrl);
    $('.community-tabs').addEventListener('keydown', (event) => {
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        const current = tabs.indexOf(document.activeElement);
        if (current < 0) return;
        event.preventDefault();
        const index = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1
            : (current + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
        tabs[index].focus({ preventScroll: true });
        applyFilter(tabs[index].dataset.filter);
    });

    function resetDraft() {
        draft.value = '';
        category.value = '';
        $('#community-draft-count').textContent = '0 / 5000';
        attachments.forEach((item) => URL.revokeObjectURL(item.url));
        attachments.length = 0;
        previews.replaceChildren();
        $('[data-attachment-status]').textContent = '';
        $$('[data-file-kind]').forEach((input) => { input.value = ''; });
        $$('[data-upload-kind]').forEach((label) => label.classList.remove('is-active'));
        discard.hidden = true;
        error.hidden = true;
    }
    function requestClose() {
        if (draft.value.trim() || category.value || attachments.length) {
            discard.hidden = false;
            $('[data-keep-draft]').focus();
        } else {
            resetDraft();
            create.close();
        }
    }
    create.addEventListener('cancel', (event) => {
        event.preventDefault();
        if (!discard.hidden) {
            discard.hidden = true;
            draft.focus();
        } else requestClose();
    });
    draft.addEventListener('input', () => {
        $('#community-draft-count').textContent = draft.value.length + ' / 5000';
    });

    function sizeLabel(bytes) {
        return bytes < 1024 * 1024 ? Math.max(1, Math.round(bytes / 1024)) + ' KB'
            : (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }
    function renderAttachment(item) {
        const li = document.createElement('li');
        li.className = 'community-preview';
        li.dataset.attachmentId = item.id;
        if (item.kind === 'image') {
            const image = document.createElement('img');
            image.src = item.url;
            image.alt = 'Vista previa de ' + item.file.name;
            li.append(image);
        } else if (item.kind === 'video') {
            const video = document.createElement('video');
            video.src = item.url;
            video.controls = true;
            video.preload = 'metadata';
            video.setAttribute('aria-label', 'Vista previa de ' + item.file.name);
            li.append(video);
        } else {
            const icon = document.createElement('div');
            icon.className = 'community-preview-document';
            icon.append(icons.document.cloneNode(true));
            li.append(icon);
        }
        const info = document.createElement('div');
        info.className = 'community-preview-info';
        const name = document.createElement('strong');
        name.textContent = item.file.name;
        const meta = document.createElement('span');
        meta.textContent = item.extension.toUpperCase() + ' · ' + sizeLabel(item.file.size);
        info.append(name, meta);
        if (item.extension === 'pdf') {
            const link = document.createElement('a');
            link.href = item.url;
            link.target = '_blank';
            link.rel = 'noopener';
            link.textContent = 'Abrir vista previa';
            info.append(link);
        }
        li.append(info);
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'community-icon-button';
        remove.dataset.removeAttachment = item.id;
        remove.setAttribute('aria-label', 'Retirar ' + item.file.name);
        remove.append(closeIcon.cloneNode(true));
        li.append(remove);
        previews.append(li);
    }
    $$('[data-file-kind]').forEach((input) => input.addEventListener('change', () => {
        const messages = [];
        for (const file of input.files) {
            const kind = input.dataset.fileKind;
            const extension = file.name.split('.').pop().toLowerCase();
            if (!types[kind][extension] || (file.type && !types[kind][extension].includes(file.type))) {
                messages.push(file.name + ': formato no admitido.'); continue;
            }
            if (!file.size || file.size > limits[kind] * 1024 * 1024) {
                messages.push(file.name + ': el tamaño debe ser mayor que 0 y como máximo ' + limits[kind] + ' MB.'); continue;
            }
            if (attachments.some((item) => item.file.name === file.name && item.file.size === file.size && item.file.lastModified === file.lastModified)) {
                messages.push(file.name + ': ya está añadido.'); continue;
            }
            if (attachments.length >= 4 || attachments.reduce((sum, item) => sum + item.file.size, 0) + file.size > 80 * 1024 * 1024) {
                messages.push(file.name + ': máximo 4 archivos y 80 MB en total.'); continue;
            }
            const item = { id: String(++sequence), file, kind, extension, url: URL.createObjectURL(file) };
            attachments.push(item);
            renderAttachment(item);
        }
        error.textContent = messages.join(' ');
        error.hidden = messages.length === 0;
        $('[data-attachment-status]').textContent = attachments.length + ' archivos adjuntos.';
        input.value = '';
    }));

    function openPost(article, trigger) {
        const content = article.querySelector('[data-post-content]').cloneNode(true);
        content.querySelectorAll('[id]').forEach((element) => element.removeAttribute('id'));
        $('[data-community-detail]').replaceChildren(content);
        open(detail, trigger);
    }
    community.addEventListener('click', async (event) => {
        const target = event.target.closest('button');
        if (!target) return;
        if (target.matches('[data-filter]')) applyFilter(target.dataset.filter);
        if (target.matches('[data-topic]')) {
            activeTopic = activeTopic === target.dataset.topic ? '' : target.dataset.topic;
            applyFilter();
        }
        if (target.matches('[data-reset-filter]')) { activeTopic = ''; applyFilter('para-ti'); }
        if (target.matches('[data-show-unanswered]')) { activeTopic = ''; applyFilter('sin-respuesta'); }
        if (target.matches('[data-open-create]')) {
            open(create, target);
            const kind = target.dataset.openCreate;
            $$('[data-upload-kind]').forEach((label) => label.classList.toggle('is-active', label.dataset.uploadKind === kind));
            if (kind) create.querySelector('[data-file-kind="' + kind + '"]').focus(); else draft.focus();
        }
        if (target.matches('[data-close-create]')) requestClose();
        if (target.matches('[data-keep-draft]')) { discard.hidden = true; draft.focus(); }
        if (target.matches('[data-discard-draft]')) { resetDraft(); create.close(); }
        if (target.matches('[data-remove-attachment]')) {
            const index = attachments.findIndex((item) => item.id === target.dataset.removeAttachment);
            if (index >= 0) {
                URL.revokeObjectURL(attachments[index].url);
                attachments.splice(index, 1);
                target.closest('li').remove();
                $('[data-attachment-status]').textContent = attachments.length + ' archivos adjuntos.';
                error.hidden = true;
                create.querySelector('[data-file-kind]').focus();
            }
        }
        if (target.matches('[data-open-post]')) openPost(target.closest('article'), target);
        if (target.matches('[data-post-target]')) {
            const article = document.getElementById(target.dataset.postTarget);
            if (article) openPost(article, target);
        }
        if (target.matches('[data-close-dialog]')) detail.close();
        if (target.matches('[data-save-post]')) {
            const saved = target.getAttribute('aria-pressed') !== 'true';
            target.setAttribute('aria-pressed', String(saved));
            target.setAttribute('aria-label', saved ? 'Quitar de guardados de esta sesión' : 'Guardar en esta sesión');
            target.closest('article').querySelector('[data-action-status]').textContent = saved ? 'Guardado durante esta sesión.' : 'Retirado de guardados.';
        }
        if (target.matches('[data-share-post]')) {
            const url = new URL(location.href);
            url.search = '';
            url.hash = target.closest('article').id;
            const status = target.closest('article').querySelector('[data-action-status]');
            try {
                await navigator.clipboard.writeText(url.href);
                status.textContent = 'Enlace copiado.';
            } catch {
                status.textContent = 'Enlace: ' + url.href;
            }
        }
    });
    window.addEventListener('beforeunload', (event) => {
        if (create.open && (draft.value.trim() || category.value || attachments.length)) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
    window.addEventListener('pagehide', (event) => {
        // A page kept in the back/forward cache still needs its preview URLs.
        if (!event.persisted) attachments.forEach((item) => URL.revokeObjectURL(item.url));
    });
    const incoming = takeHandoff('community');
    if (incoming && typeof incoming.text === 'string') {
        community.querySelector('[data-open-create]').click();
        draft.value = incoming.text.slice(0, 2000);
        if ([...category.options].some((option) => option.value === incoming.category)) category.value = incoming.category;
        draft.dispatchEvent(new Event('input', { bubbles: true }));
        draft.focus();
    }
}
