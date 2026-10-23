import { onPage, navigate } from './ui/navigation.js';
import { validateAttachment, createAttachment, formatBytes, releaseAttachment } from './messages/attachments';


onPage('[data-messaging]', (root, { listen, onCleanup }) => {
    const $ = (selector, scope = root) => scope.querySelector(selector);
    const $$ = (selector, scope = root) => [...scope.querySelectorAll(selector)];
    const clone = (name) => $('[data-' + name + '-template]').content.firstElementChild.cloneNode(true);
    const data = JSON.parse($('[data-messaging-data]').textContent);
    const conversations = data.conversations.map((conversation) => ({ ...conversation, draft: '', draftAttachments: [] }));
    const history = $('[data-history]');
    const input = $('[data-draft]');
    const info = $('[data-information]');
    const infoDialog = $('[data-info-dialog]');
    const previewDialog = $('[data-preview-dialog]');
    const desktop = matchMedia('(min-width: 1200px)');
    const narrow = matchMedia('(max-width: 639px)');
    const time = new Intl.DateTimeFormat('es-MX', { hour: '2-digit', minute: '2-digit' });
    const day = new Intl.DateTimeFormat('es-MX', { day: 'numeric', month: 'long', year: 'numeric' });
    let current = conversations[0] || null;
    let filter = 'all';
    let expandedFiles = false;
    let infoTrigger;
    let previewTrigger;
    let originalOverflow = '';
    let sequence = 0;
    const buttons = new Map();
    const state = data.state || 'ready';

    function setStatus(text) { $('[data-message-status]').textContent = text; }
    function attachable() { return current && state === 'ready'; }
    function lastPreview(conversation) {
        const last = conversation.messages.at(-1);
        return last?.text || last?.attachments?.at(-1)?.name || 'Inicia una conversación';
    }
    for (const conversation of conversations) {
        const button = clone('conversation');
        button.dataset.conversationId = conversation.id;
        $('[data-list-name]', button).textContent = conversation.contact.name;
        $('[data-list-initials]', button).textContent = conversation.contact.initials;
        $('[data-list-verified]', button).toggleAttribute('hidden', !conversation.contact.verified);
        $('[data-conversation-list]').append(button);
        buttons.set(conversation.id, button);
    }
    function renderList() {
        const query = $('[data-search]').value.trim().normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('es');
        let count = 0;
        for (const conversation of conversations) {
            const button = buttons.get(conversation.id);
            const name = conversation.contact.name.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('es');
            button.hidden = !name.includes(query) || (filter === 'unread' && conversation.unread_count === 0);
            if (!button.hidden) count++;
            button.setAttribute('aria-current', conversation === current ? 'true' : 'false');
            $('[data-list-last]', button).textContent = lastPreview(conversation);
            const last = conversation.messages.at(-1);
            $('[data-list-time]', button).textContent = last ? time.format(new Date(last.created_at)) : '';
            if (last) $('[data-list-time]', button).dateTime = last.created_at;
            $('[data-list-unread]', button).textContent = conversation.unread_count;
            $('[data-list-unread]', button).hidden = !conversation.unread_count;
            $('[data-list-unread]', button).setAttribute('aria-label', conversation.unread_count + ' mensajes no leídos');
        }
        $('[data-list-empty]').hidden = count > 0 || state !== 'ready';
        $('[data-list-status]').textContent = state === 'ready' ? count + (count === 1 ? ' conversación' : ' conversaciones') : '';
        $$('[data-list-filter]').forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.listFilter === filter)));
    }

    function fileButton(file) {
        const button = clone('file');
        button.dataset.fileId = file.id;
        $('[data-file-name]', button).textContent = file.name;
        $('[data-file-meta]', button).textContent = file.name.split('.').pop().toUpperCase() + ' · ' + formatBytes(file.size);
        if (file.kind === 'video') $('[data-file-icon]', button).replaceWith(clone('video'));
        if (file.kind === 'image') {
            const image = document.createElement('img');
            image.src = file.url;
            image.alt = '';
            image.loading = 'lazy';
            $('[data-file-icon]', button).replaceWith(image);
        }
        return button;
    }
    function allFiles() {
        return current ? current.messages.flatMap((message) => message.attachments || []) : [];
    }
    function renderInfo() {
        $$('[data-contact-name]').forEach((element) => { element.textContent = current?.contact.name || 'Selecciona una conversación'; });
        $$('[data-contact-initials]').forEach((element) => { element.textContent = current?.contact.initials || ''; });
        $$('[data-contact-verified]').forEach((element) => element.toggleAttribute('hidden', !current?.contact.verified));
        $$('[data-contact-specialty]').forEach((element) => {
            element.textContent = current?.contact.specialty || '';
            element.hidden = !current?.contact.specialty;
        });
        $('[data-open-info]').disabled = !current;
        const files = allFiles();
        $('[data-shared-files]').replaceChildren(...(expandedFiles ? files : files.slice(0, 3)).map(fileButton));
        $('[data-shared-empty]').hidden = files.length > 0;
        const more = $('[data-all-files]');
        more.hidden = files.length <= 3;
        more.setAttribute('aria-expanded', String(expandedFiles));
        more.firstChild.textContent = expandedFiles ? 'Ver menos ' : 'Ver todos ';
        const profile = $('[data-profile]');
        // Only use an explicitly supplied, same-origin profile URL; never infer a contact route.
        let profileUrl = null;
        if (current?.contact.profile_url) {
            try {
                const url = new URL(current.contact.profile_url, location.origin);
                if (url.origin === location.origin && ['http:', 'https:'].includes(url.protocol)) profileUrl = url.href;
            } catch { /* No connected profile URL. */ }
        }
        profile.disabled = !profileUrl;
        if (profileUrl) profile.dataset.url = profileUrl; else delete profile.dataset.url;
    }
    function appendMessage(message) {
        const bubble = clone('message');
        const own = message.sender_id === data.current_user.id;
        bubble.classList.toggle('is-own', own);
        bubble.setAttribute('aria-label', (own ? 'Tú' : current.contact.name) + ', ' + time.format(new Date(message.created_at)));
        $('[data-message-text]', bubble).textContent = message.text;
        $('[data-message-text]', bubble).hidden = !message.text;
        $('[data-message-time]', bubble).textContent = time.format(new Date(message.created_at));
        $('[data-message-time]', bubble).dateTime = message.created_at;
        $('[data-message-state]', bubble).textContent = message.local ? 'Solo aquí' : own && message.read_at ? 'Leído' : '';
        for (const file of message.attachments || []) {
            $('[data-message-files]', bubble).append(fileButton(file));
        }
        history.append(bubble);
    }
    function scrollToEnd() {
        requestAnimationFrame(() => { history.scrollTop = history.scrollHeight; });
    }
    function renderHistory() {
        history.replaceChildren();
        if (state === 'loading') { history.append(clone('skeleton')); return; }
        if (state === 'error' || !current) {
            const empty = document.createElement('p');
            empty.className = 'messages-empty-chat';
            empty.textContent = state === 'error' ? 'No pudimos cargar tus conversaciones. Inténtalo de nuevo más tarde.' : 'Selecciona una conversación para comenzar.';
            history.append(empty);
            return;
        }
        let lastDay = '';
        for (const message of current.messages) {
            const date = day.format(new Date(message.created_at));
            if (date !== lastDay) {
                const divider = document.createElement('p');
                divider.className = 'messages-date';
                divider.textContent = date;
                history.append(divider);
                lastDay = date;
            }
            appendMessage(message);
        }
        if (!current.messages.length) {
            const empty = document.createElement('p');
            empty.className = 'messages-empty-chat';
            empty.textContent = 'Esta conversación está por comenzar.';
            history.append(empty);
        }
        scrollToEnd();
    }
    function resizeDraft() {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 128) + 'px';
    }
    function updateSend() {
        $('[data-send]').disabled = !attachable() || (!input.value.trim() && !current.draftAttachments.length);
    }
    function renderDraft() {
        input.disabled = !attachable();
        $('[data-attach]').disabled = !attachable();
        input.value = current?.draft || '';
        $('[data-draft-files]').replaceChildren();
        for (const file of current?.draftAttachments || []) {
            const item = document.createElement('li');
            item.append(fileButton(file));
            const remove = clone('remove');
            remove.dataset.removeFile = file.id;
            remove.setAttribute('aria-label', 'Retirar ' + file.name);
            item.append(remove);
            $('[data-draft-files]').append(item);
        }
        $('[data-file-error]').hidden = true;
        resizeDraft();
        updateSend();
    }
    function selectConversation(id, trigger) {
        const next = conversations.find((item) => item.id === id);
        if (!next) return;
        current = next;
        current.unread_count = 0; // Local visual read state only.
        expandedFiles = false;
        root.dataset.view = 'chat';
        renderList(); renderInfo(); renderHistory(); renderDraft();
        if (trigger && narrow.matches) $('#messages-contact-name').focus({ preventScroll: true });
    }
    function send() {
        if (!attachable() || $('[data-send]').disabled) return;
        const message = {
            id: 'local-' + (++sequence), sender_id: data.current_user.id, text: input.value.trim(),
            created_at: new Date().toISOString(), read_at: null, attachments: current.draftAttachments, local: true,
        };
        current.messages.push(message);
        current.draft = '';
        current.draftAttachments = [];
        renderHistory(); renderDraft(); renderList(); renderInfo();
        input.focus({ preventScroll: true });
        setStatus('Mensaje añadido solo a esta página.');
    }

    function closeInfo() { if (infoDialog.open) infoDialog.close(); }
    infoDialog.addEventListener('close', () => {
        $('.messages-layout').append(info);
        document.body.style.overflow = originalOverflow;
        infoTrigger?.focus({ preventScroll: true });
    });
    function openInfo(trigger) {
        if (!current) return;
        if (desktop.matches) { $('#messages-info-title').focus({ preventScroll: true }); return; }
        infoTrigger = trigger;
        originalOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        infoDialog.append(info);
        infoDialog.showModal();
    }
    listen(desktop, 'change', () => { if (desktop.matches) closeInfo(); });
    function findFile(id) {
        return [...allFiles(), ...(current?.draftAttachments || [])].find((file) => file.id === id);
    }
    function openFile(file, trigger) {
        if (!file) return;
        previewTrigger = trigger;
        const content = $('[data-file-preview]');
        content.replaceChildren();
        $('#messages-preview-title').textContent = file.name;
        if (file.kind === 'image' || file.kind === 'video') {
            const media = document.createElement(file.kind === 'image' ? 'img' : 'video');
            media.src = file.url;
            if (file.kind === 'image') media.alt = file.name;
            else { media.controls = true; media.preload = 'metadata'; }
            content.append(media);
        }
        const link = clone('download');
        link.href = file.url;
        link.target = '_blank';
        link.rel = 'noopener';
        content.append(link);
        previewDialog.showModal();
    }
    previewDialog.addEventListener('close', () => {
        $('[data-file-preview]').replaceChildren();
        previewTrigger?.focus({ preventScroll: true });
    });
    root.addEventListener('click', (event) => {
        const target = event.target.closest('button');
        if (!target) return;
        if (target.matches('[data-conversation-id]')) selectConversation(target.dataset.conversationId, target);
        if (target.matches('[data-list-filter]')) { filter = target.dataset.listFilter; renderList(); }
        if (target.matches('[data-back]')) {
            root.dataset.view = 'list';
            const selectedButton = buttons.get(current?.id);
            (selectedButton && !selectedButton.hidden ? selectedButton : $('[data-search]')).focus({ preventScroll: true });
        }
        if (target.matches('[data-open-info]')) openInfo(target);
        if (target.matches('[data-close-info]')) closeInfo();
        if (target.matches('[data-all-files]')) { expandedFiles = !expandedFiles; renderInfo(); $('[data-all-files]').focus(); }
        if (target.matches('[data-profile]') && target.dataset.url) navigate(target.dataset.url);
        if (target.matches('[data-file-id]')) openFile(findFile(target.dataset.fileId), target);
        if (target.matches('[data-close-preview]')) previewDialog.close();
        if (target.matches('[data-remove-file]')) {
            const index = current.draftAttachments.findIndex((file) => file.id === target.dataset.removeFile);
            if (index >= 0) {
                releaseAttachment(current.draftAttachments[index]);
                current.draftAttachments.splice(index, 1);
                renderDraft();
                $('[data-attach]').focus();
                setStatus('Archivo retirado del borrador.');
            }
        }
    });
    $('[data-search]').addEventListener('input', renderList);
    input.addEventListener('input', () => { if (current) current.draft = input.value; resizeDraft(); updateSend(); });
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) { event.preventDefault(); send(); }
    });
    $('[data-composer]').addEventListener('submit', (event) => { event.preventDefault(); send(); });
    $('[data-attach]').addEventListener('change', (event) => {
        if (!attachable()) return;
        const errors = [];
        for (const file of event.target.files) {
            const error = validateAttachment(file, current.draftAttachments);
            if (error) errors.push(file.name + ': ' + error);
            else current.draftAttachments.push(createAttachment(file));
        }
        renderDraft();
        const error = $('[data-file-error]');
        error.textContent = errors.join(' ');
        error.hidden = !errors.length;
        event.target.value = '';
        setStatus(current.draftAttachments.length + ' archivos en el borrador.');
    });
    onCleanup( (event) => {
        if (!event.persisted) conversations.forEach((conversation) => {
            [...conversation.draftAttachments, ...conversation.messages.flatMap((message) => message.attachments || [])].forEach(releaseAttachment);
        });
    });
    function viewport() {
        root.style.setProperty('--messages-viewport', (window.visualViewport?.height || window.innerHeight) + 'px');
    }
    listen(window.visualViewport, 'resize', viewport);
    listen(window, 'resize', viewport);
    viewport();
    $('#messages-contact-name').tabIndex = -1;
    $('#messages-info-title').tabIndex = -1;
    renderList(); renderInfo(); renderHistory(); renderDraft();
    const linkedConversation = location.hash.slice(1);
    if (state === 'ready' && conversations.some((item) => item.id === linkedConversation)) selectConversation(linkedConversation, true);
    if (state === 'loading') {
        $('[data-conversation-list]').replaceChildren(clone('skeleton'));
        $('[data-search]').disabled = true;
    }
    if (state === 'error') {
        $('[data-conversation-list]').replaceChildren();
        $('[data-list-empty]').hidden = false;
        $('[data-list-empty]').textContent = 'Las conversaciones no están disponibles por el momento.';
    }
});
