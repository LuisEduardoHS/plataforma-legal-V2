import { createDraft, createSession, appendExchange, matchesSession, validateContext } from './lexa/state.js';
import { createAttachment, releaseAttachment, formatBytes } from './messages/attachments.js';
import { takeHandoff } from './ui/handoff.js';

const root = document.querySelector('[data-lexa]');
if (root) {
    const $ = (selector, scope = root) => scope.querySelector(selector);
    const $$ = (selector, scope = root) => [...scope.querySelectorAll(selector)];
    const clone = (name) => $('[data-' + name + '-template]').content.firstElementChild.cloneNode(true);
    const data = JSON.parse($('[data-lexa-data]').textContent);
    const sessions = data.sessions.map((session) => ({ ...session, draft: createDraft(session.mode) }));
    const sidebar = $('[data-sidebar]');
    const sidebarDialog = $('[data-history-dialog]');
    const previewDialog = $('[data-preview-dialog]');
    const prompt = $('[data-prompt]');
    const mode = $('[data-consultation-mode]');
    const thread = $('[data-thread]');
    const sidebarBreakpoint = matchMedia('(min-width: 768px)');
    const buttons = new Map();
    let selectedId = null;
    let welcomeDraft = createDraft();
    let sidebarTrigger;
    let previewTrigger;
    let previousOverflow = '';

    function currentSession() { return sessions.find((session) => session.id === selectedId); }
    function currentDraft() { return currentSession()?.draft || welcomeDraft; }
    function announce(text) { $('[data-lexa-status]').textContent = text; }
    function renderHistory() {
        for (const session of sessions) {
            let button = buttons.get(session.id);
            if (!button) {
                button = clone('session');
                button.dataset.sessionId = session.id;
                buttons.set(session.id, button);
            }
            $('span', button).textContent = session.title;
            button.title = session.title;
            button.setAttribute('aria-current', String(session.id === selectedId));
            button.hidden = !matchesSession(session, $('[data-search]').value);
            $('[data-history-list]').append(button);
        }
        $('[data-history-empty]').hidden = [...buttons.values()].some((button) => !button.hidden);
    }
    function updateComposer() {
        $('[data-send]').disabled = !prompt.value.trim() && !currentDraft().attachments.length;
        prompt.style.height = 'auto';
        prompt.style.height = Math.min(prompt.scrollHeight, 180) + 'px';
    }
    function updateContext() {
        const session = currentSession();
        $('[data-context-details]').hidden = !session;
        const files = session ? [...session.messages.flatMap((message) => message.attachments || []), ...session.draft.attachments] : [];
        $('[data-context-title]').textContent = session?.title || '';
        $('[data-context-mode]').textContent = session ? data.modes.find((item) => item.id === session.mode)?.label || '' : '';
        $('[data-context-count]').textContent = files.length ? files.length + (files.length === 1 ? ' archivo' : ' archivos') : '';
        $('[data-context-files]').replaceChildren(...files.map(fileButton));
        $('[data-context-empty]').hidden = files.length > 0;
    }
    function fileButton(file) {
        const button = clone('file');
        button.dataset.previewFile = file.id;
        $('[data-file-name]', button).textContent = file.name;
        $('[data-file-meta]', button).textContent = file.name.split('.').pop().toUpperCase() + ' · ' + formatBytes(file.size);
        if (file.kind === 'image') {
            const image = document.createElement('img');
            image.src = file.url;
            image.alt = '';
            $('[data-file-icon]', button).replaceWith(image);
        }
        return button;
    }
    function renderFiles() {
        $('[data-draft-files]').replaceChildren();
        for (const file of currentDraft().attachments) {
            const li = document.createElement('li');
            li.append(fileButton(file));
            const remove = clone('remove');
            remove.dataset.removeContext = file.id;
            remove.setAttribute('aria-label', 'Retirar ' + file.name);
            li.append(remove);
            $('[data-draft-files]').append(li);
        }
        updateContext();
    }
    function renderComposer() {
        prompt.value = currentDraft().text;
        mode.value = currentDraft().mode;
        $('[data-file-error]').hidden = true;
        renderFiles();
        updateComposer();
    }
    function renderThread() {
        const session = currentSession();
        root.dataset.stage = session ? 'thread' : 'welcome';
        prompt.rows = session ? 1 : 3;
        $('[data-welcome]').hidden = !!session;
        $('[data-suggestions]').hidden = !!session;
        thread.hidden = !session;
        $('[data-session-title]').textContent = session?.title || 'Nueva consulta';
        thread.replaceChildren();
        if (!session) return;
        for (const message of session.messages) {
            const article = clone('message');
            const own = message.role === 'user';
            article.classList.toggle('is-user', own);
            $('[data-message-author]', article).textContent = own ? data.current_user.name : 'LEXA';
            $('[data-answer-icon]', article).toggleAttribute('hidden', own);
            $('[data-example-label]', article).hidden = !message.example;
            $('[data-message-text]', article).textContent = message.text;
            for (const file of message.attachments || []) $('[data-message-files]', article).append(fileButton(file));
            thread.append(article);
        }
        requestAnimationFrame(() => { thread.scrollTop = thread.scrollHeight; });
    }
    function closeSidebar() { if (sidebarDialog.open) sidebarDialog.close(); }
    sidebarDialog.addEventListener('close', () => {
        $('.lexa-layout').prepend(sidebar);
        document.body.style.overflow = previousOverflow;
        sidebarTrigger?.focus({ preventScroll: true });
    });
    function openSidebar(trigger) {
        sidebarTrigger = trigger;
        previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        sidebarDialog.append(sidebar);
        sidebarDialog.showModal();
        $('[data-search]').focus();
    }
    sidebarBreakpoint.addEventListener('change', () => { if (sidebarBreakpoint.matches) closeSidebar(); });
    function focusPrompt() {
        prompt.focus({ preventScroll: true });
        if (!currentSession()) requestAnimationFrame(() => prompt.scrollIntoView({ block: 'nearest', behavior: 'instant' }));
    }
    function selectSession(id) {
        selectedId = id;
        renderThread();
        renderComposer();
        renderHistory();
        closeSidebar();
        // The sidebar close event restores the opener first.
        requestAnimationFrame(focusPrompt);
    }
    function newSession() {
        // Existing conversations keep their drafts; a fresh composer is cleared.
        welcomeDraft.attachments.forEach(releaseAttachment);
        selectedId = null;
        welcomeDraft = createDraft();
        renderThread(); renderComposer(); renderHistory();
        closeSidebar();
        requestAnimationFrame(focusPrompt);
        announce('Nueva consulta lista.');
    }
    function send() {
        const draft = currentDraft();
        draft.text = prompt.value;
        if (!draft.text.trim() && !draft.attachments.length) return;
        let session = currentSession();
        if (!session) {
            session = createSession(draft.text, draft.mode);
            sessions.unshift(session);
            selectedId = session.id;
        }
        const answer = data.example_responses[draft.mode] || data.example_responses.orientation;
        appendExchange(session, draft, answer);
        if (draft === welcomeDraft) welcomeDraft = createDraft();
        renderThread(); renderComposer(); renderHistory();
        focusPrompt();
        announce('Consulta añadida a la sesión local. LEXA muestra una respuesta de ejemplo.');
    }
    function findFile(id) {
        return [...(currentSession()?.messages.flatMap((message) => message.attachments || []) || []), ...currentDraft().attachments].find((file) => file.id === id);
    }
    function preview(file, trigger) {
        if (!file) return;
        previewTrigger = trigger;
        $('[data-preview-content]').replaceChildren();
        $('#lexa-preview-title').textContent = file.name;
        if (file.kind === 'image') {
            const image = document.createElement('img');
            image.src = file.url;
            image.alt = file.name;
            $('[data-preview-content]').append(image);
        }
        const link = clone('preview-link');
        link.href = file.url;
        link.target = '_blank';
        link.rel = 'noopener';
        $('[data-preview-content]').append(link);
        previewDialog.showModal();
    }
    previewDialog.addEventListener('close', () => {
        $('[data-preview-content]').replaceChildren();
        previewTrigger?.focus({ preventScroll: true });
    });
    root.addEventListener('click', (event) => {
        const target = event.target.closest('button');
        if (!target) return;
        if (target.matches('[data-new-session]')) newSession();
        if (target.matches('[data-session-id]')) selectSession(target.dataset.sessionId);
        if (target.matches('[data-open-history]')) openSidebar(target);
        if (target.matches('[data-close-history]')) closeSidebar();
        if (target.matches('[data-suggestion]')) {
            currentDraft().text = target.dataset.suggestion;
            currentDraft().mode = target.dataset.mode;
            renderComposer();
            focusPrompt();
        }
        if (target.matches('[data-remove-context]')) {
            const files = currentDraft().attachments;
            const index = files.findIndex((file) => file.id === target.dataset.removeContext);
            if (index >= 0) {
                releaseAttachment(files[index]);
                files.splice(index, 1);
                renderFiles(); updateComposer();
                $('[data-file-error]').hidden = true;
                $('[data-attach]').focus();
                announce('Archivo retirado del contexto.');
            }
        }
        if (target.matches('[data-preview-file]')) preview(findFile(target.dataset.previewFile), target);
        if (target.matches('[data-close-preview]')) previewDialog.close();
    });
    $('[data-search]').addEventListener('input', renderHistory);
    prompt.addEventListener('input', () => { currentDraft().text = prompt.value; updateComposer(); });
    prompt.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) { event.preventDefault(); send(); }
    });
    mode.addEventListener('change', () => { currentDraft().mode = mode.value; });
    $('[data-composer]').addEventListener('submit', (event) => { event.preventDefault(); send(); });
    $('[data-attach]').addEventListener('change', (event) => {
        const errors = [];
        for (const file of event.target.files) {
            const error = validateContext(file, currentDraft().attachments);
            if (error) errors.push(file.name + ': ' + error);
            else currentDraft().attachments.push(createAttachment(file));
        }
        event.target.value = '';
        renderFiles(); updateComposer();
        $('[data-file-error]').textContent = errors.join(' ');
        $('[data-file-error]').hidden = !errors.length;
        announce(currentDraft().attachments.length + ' archivos de contexto.');
    });
    function resizeViewport() {
        root.style.setProperty('--lexa-viewport', (window.visualViewport?.height || window.innerHeight) + 'px');
        if (document.activeElement === prompt && !currentSession()) requestAnimationFrame(() => prompt.scrollIntoView({ block: 'nearest', behavior: 'instant' }));
    }
    window.visualViewport?.addEventListener('resize', resizeViewport);
    window.addEventListener('resize', resizeViewport);
    window.addEventListener('pagehide', (event) => {
        if (!event.persisted) {
            welcomeDraft.attachments.forEach(releaseAttachment);
            sessions.forEach((session) => [...session.draft.attachments, ...session.messages.flatMap((message) => message.attachments || [])].forEach(releaseAttachment));
        }
    });
    resizeViewport(); renderHistory(); renderThread(); renderComposer();
    const incoming = takeHandoff('lexa');
    if (incoming) {
        if (sessions.some((session) => session.id === incoming.sessionId)) {
            selectSession(incoming.sessionId);
        } else {
            welcomeDraft.text = typeof incoming.text === 'string' ? incoming.text.slice(0, 5000) : '';
            welcomeDraft.mode = data.modes.some((item) => item.id === incoming.mode) ? incoming.mode : 'orientation';
            renderComposer();
            focusPrompt();
            if (incoming.context) {
                requestAnimationFrame(() => {
                    $('[data-attach]').focus();
                    $('[data-attach]').scrollIntoView({ block: 'nearest' });
                });
            }
        }
    }
}
