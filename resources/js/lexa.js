import { onPage } from './ui/page.js';
import { createDraft, matchesSession, normalizeLexaSession, formatApiError } from './lexa/state.js';
import { renderLegalCitations } from './lexa/citations.js';
import { renderMarkdownToFragment } from './lexa/markdown.js';
import { takeHandoff } from './ui/handoff.js';

export function setupLexa(root, { listen, onCleanup, onBeforeVisit }) {
    const $ = (selector, scope = root) => scope.querySelector(selector);
    const clone = (name) => $('[data-' + name + '-template]').content.firstElementChild.cloneNode(true);
    const data = JSON.parse($('[data-lexa-data]').textContent);
    const baseUrl = (data.endpoints?.conversations || '/lexa/conversaciones').replace(/\/$/, '');
    const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const sessions = (data.sessions || []).map(normalizeLexaSession);
    let sessionsNextCursor = data.next_cursor || null;

    const sidebar = $('[data-sidebar]');
    const sidebarDialog = $('[data-history-dialog]');
    const deleteDialog = $('[data-delete-dialog]');
    const prompt = $('[data-prompt]');
    const mode = $('[data-consultation-mode]');
    const thread = $('[data-thread]');
    const threadList = $('[data-thread-list]');
    const banner = $('[data-lexa-banner]');
    const bannerText = $('[data-lexa-banner-text]');
    const bannerRetry = $('[data-lexa-banner-retry]');
    const bannerReload = $('[data-lexa-banner-reload]');
    const bannerLogin = $('[data-lexa-banner-login]');
    const deleteBtn = $('[data-delete-session]');
    const loadMoreSessionsBtn = $('[data-load-more-sessions]');
    const loadMoreMessagesBtn = $('[data-load-more-messages]');
    const sidebarBreakpoint = matchMedia('(min-width: 768px)');
    const buttons = new Map();
    const controllers = new Set();
    const generatingSessions = new Set();

    let selectedId = null;
    let welcomeDraft = createDraft();
    let sidebarTrigger;
    let deleteTrigger;
    let previousOverflow = '';
    let isSubmitting = false;
    let isLoadingThread = false;
    let isLoadingSessions = false;
    let activeThreadController = null;
    let activeSessionsController = null;
    let disposed = false;

    function currentSession() {
        return sessions.find((session) => session.id === selectedId) || null;
    }

    function currentDraft() {
        return currentSession()?.draft || welcomeDraft;
    }

    function isCurrentSessionBusy() {
        return isSubmitting || (selectedId !== null && generatingSessions.has(selectedId));
    }

    function announce(text) {
        if (disposed) return;
        const statusNode = $('[data-lexa-status]');
        if (statusNode) statusNode.textContent = text;
    }

    function clearBanner() {
        banner.hidden = true;
        banner.classList.remove('is-error', 'is-generating');
        bannerText.textContent = '';
        bannerRetry.hidden = true;
        bannerReload.hidden = true;
        bannerLogin.hidden = true;
    }

    function showBanner(text, { error = false, generating = false, retry = false, reload = false, login = false } = {}) {
        if (disposed) return;
        bannerText.textContent = text;
        banner.classList.toggle('is-error', error);
        banner.classList.toggle('is-generating', generating);
        bannerRetry.hidden = !retry;
        bannerReload.hidden = !reload;
        bannerLogin.hidden = !login;
        banner.hidden = false;
    }

    function syncGenerationBanner() {
        if (selectedId !== null && generatingSessions.has(selectedId)) {
            showBanner('Generando respuesta…', { error: false, generating: true });
        } else {
            clearBanner();
        }
    }

    function abortActiveThreadLoad() {
        if (activeThreadController) {
            activeThreadController.abort();
            controllers.delete(activeThreadController);
            activeThreadController = null;
        }
        isLoadingThread = false;
    }

    function abortActiveSessionsLoad() {
        if (activeSessionsController) {
            activeSessionsController.abort();
            controllers.delete(activeSessionsController);
            activeSessionsController = null;
        }
        isLoadingSessions = false;
    }

    async function apiFetch(url, options = {}) {
        const controller = new AbortController();
        controllers.add(controller);
        const signal = options.signal || controller.signal;
        const headers = {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(options.headers || {}),
        };
        if (options.method && options.method !== 'GET') {
            headers['Content-Type'] = 'application/json';
            headers['X-CSRF-TOKEN'] = csrfToken();
        }
        try {
            const response = await fetch(url, {
                ...options,
                headers,
                credentials: 'same-origin',
                signal,
            });
            let json = null;
            try {
                json = await response.json();
            } catch {
                json = null;
            }
            return { ok: response.ok, status: response.status, json };
        } finally {
            controllers.delete(controller);
        }
    }

    function renderHistory() {
        if (disposed) return;
        const list = $('[data-history-list]');
        const query = $('[data-search]').value;
        const activeIds = new Set(sessions.map((s) => s.id));
        for (const [id, button] of buttons.entries()) {
            if (!activeIds.has(id)) {
                button.remove();
                buttons.delete(id);
            }
        }
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
            button.classList.toggle('is-generating', generatingSessions.has(session.id));
            button.hidden = !matchesSession(session, query);
            list.append(button);
        }
        $('[data-history-empty]').hidden = sessions.some((s) => matchesSession(s, query));
        loadMoreSessionsBtn.hidden = !sessionsNextCursor || query.trim() !== '';
    }

    function updateComposer() {
        if (disposed) return;
        const busy = isCurrentSessionBusy();
        const hasText = prompt.value.trim().length > 0;
        const sendBtn = $('[data-send]');
        sendBtn.disabled = busy || !hasText;
        prompt.readOnly = busy;
        mode.disabled = busy || Boolean(currentSession());
        prompt.style.height = 'auto';
        prompt.style.height = Math.min(prompt.scrollHeight || 0, 180) + 'px';
    }

    function updateContext() {
        if (disposed) return;
        const session = currentSession();
        const modeLabel = session ? (data.modes.find((item) => item.id === session.mode)?.label || '') : '';
        $('[data-context-details]').hidden = !session;
        $('[data-context-title]').textContent = session?.title || '';
        $('[data-context-mode]').textContent = modeLabel;
        const toolbarMode = $('[data-toolbar-mode]');
        if (toolbarMode) {
            toolbarMode.textContent = modeLabel;
            toolbarMode.hidden = !modeLabel;
        }
        $('[data-context-count]').textContent = '';
        $('[data-context-files]').replaceChildren();
        $('[data-context-empty]').hidden = false;
    }

    function renderComposer() {
        if (disposed) return;
        prompt.value = currentDraft().text;
        mode.value = currentDraft().mode;
        $('[data-file-error]').hidden = true;
        updateContext();
        updateComposer();
    }

    function renderThread({ scrollToBottom = true } = {}) {
        if (disposed) return;
        const session = currentSession();
        root.dataset.stage = session ? 'thread' : 'welcome';
        prompt.rows = session ? 1 : 3;
        $('[data-welcome]').hidden = Boolean(session);
        $('[data-suggestions]').hidden = Boolean(session);
        thread.hidden = !session;
        deleteBtn.hidden = !session;
        $('[data-session-title]').textContent = session?.title || 'Nueva consulta';
        threadList.replaceChildren();
        if (!session) {
            loadMoreMessagesBtn.hidden = true;
            return;
        }
        loadMoreMessagesBtn.hidden = !session.nextCursor;
        for (const message of session.messages) {
            const article = clone('message');
            const own = message.role === 'user';
            const text = message.content ?? message.text ?? '';
            article.classList.toggle('is-user', own);
            $('[data-message-author]', article).textContent = own ? data.current_user.name : 'LEXA';
            const copyBtn = $('[data-copy-message]', article);
            if (copyBtn) {
                copyBtn.hidden = own || !text;
                if (!own) copyBtn.dataset.copyText = text;
            }
            const body = $('[data-message-text]', article);
            body.replaceChildren();
            if (own) {
                const p = document.createElement('p');
                p.textContent = text;
                body.append(p);
            } else {
                body.append(renderMarkdownToFragment(text));
                body.append(renderLegalCitations(message.citations, message.grounding));
            }
            threadList.append(article);
        }
        if (scrollToBottom) {
            requestAnimationFrame(() => {
                if (!disposed) thread.scrollTop = thread.scrollHeight;
            });
        }
    }

    function closeSidebar() {
        if (sidebarDialog.open) sidebarDialog.close();
    }

    listen(sidebarDialog, 'close', () => {
        if (disposed) return;
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

    listen(sidebarBreakpoint, 'change', () => {
        if (sidebarBreakpoint.matches) closeSidebar();
    });

    function focusPrompt() {
        if (disposed) return;
        prompt.focus({ preventScroll: true });
        if (!currentSession()) {
            requestAnimationFrame(() => prompt.scrollIntoView?.({ block: 'nearest', behavior: 'instant' }));
        }
    }

    async function loadMessages(session, { cursor = null, append = false } = {}) {
        abortActiveThreadLoad();
        const controller = new AbortController();
        activeThreadController = controller;
        controllers.add(controller);
        isLoadingThread = true;
        try {
            const url = new URL(`${baseUrl}/${session.id}/mensajes`, window.location.origin);
            if (cursor) url.searchParams.set('cursor', cursor);
            const { ok, status, json } = await apiFetch(url.toString(), { method: 'GET', signal: controller.signal });
            if (disposed || controller.signal.aborted) return;
            if (!ok) {
                if (selectedId === session.id) {
                    handleApiFailure(status, json?.message, session);
                }
                return;
            }
            const incoming = json?.messages || [];
            session.messages = append ? [...session.messages, ...incoming] : incoming;
            session.nextCursor = json?.next_cursor || null;
            session.loaded = true;
            if (selectedId === session.id) {
                renderThread({ scrollToBottom: !append });
            }
        } catch (error) {
            if (!disposed && !controller.signal.aborted && error?.name !== 'AbortError' && selectedId === session.id) {
                showBanner('No pudimos cargar el historial. Revisa tu conexión e inténtalo de nuevo.', { error: true, reload: true });
            }
        } finally {
            controllers.delete(controller);
            if (activeThreadController === controller) {
                activeThreadController = null;
                isLoadingThread = false;
            }
        }
    }

    function handleApiFailure(status, serverMessage, session = null) {
        if (disposed) return;
        const info = formatApiError(status, serverMessage);
        if (info.kind === 'auth') {
            showBanner(info.text, { error: true, login: true });
            return;
        }
        if (info.kind === 'missing' && session) {
            const idx = sessions.findIndex((s) => s.id === session.id);
            if (idx >= 0) sessions.splice(idx, 1);
            if (selectedId === session.id) {
                selectedId = null;
                renderThread();
                renderComposer();
            }
            renderHistory();
            showBanner(info.text, { error: true });
            return;
        }
        if (info.kind === 'conflict') {
            showBanner(info.text, { error: true, reload: Boolean(session) });
            return;
        }
        if (info.kind === 'rate') {
            showBanner(info.text, { error: true });
            return;
        }
        const canRetryGen = Boolean(session && session.messages.at(-1)?.role === 'user' && data.generation_enabled);
        showBanner(info.text, { error: true, retry: canRetryGen });
    }

    async function selectSession(id) {
        if (isSubmitting) return;
        const targetId = String(id);
        abortActiveThreadLoad();
        selectedId = targetId;
        syncGenerationBanner();
        renderThread();
        renderComposer();
        renderHistory();
        closeSidebar();
        const session = currentSession();
        if (session && !session.loaded) {
            await loadMessages(session);
        }
        if (!disposed && selectedId === targetId) {
            requestAnimationFrame(focusPrompt);
        }
    }

    function newSession() {
        if (isSubmitting) return;
        abortActiveThreadLoad();
        selectedId = null;
        clearBanner();
        welcomeDraft = createDraft();
        renderThread();
        renderComposer();
        renderHistory();
        closeSidebar();
        requestAnimationFrame(focusPrompt);
        announce('Nueva consulta lista.');
    }

    async function generateForSession(session) {
        if (!data.generation_enabled) {
            if (selectedId === session.id) {
                showBanner('Tu consulta quedó guardada. La generación automática con IA está desactivada en esta configuración.', { error: false });
            }
            return;
        }
        if (generatingSessions.has(session.id)) return;
        generatingSessions.add(session.id);
        renderHistory();
        if (selectedId === session.id) {
            updateComposer();
            showBanner('Generando respuesta…', { error: false, generating: true });
            announce('Generando respuesta.');
        }
        try {
            const attemptToken = crypto.randomUUID();
            const { ok, status, json } = await apiFetch(`${baseUrl}/${session.id}/generar`, {
                method: 'POST',
                body: JSON.stringify({ attempt_token: attemptToken, state_id: $('[data-legal-state]')?.value ? Number($('[data-legal-state]').value) : null }),
            });
            if (disposed) return;
            if (!ok) {
                if (selectedId === session.id) {
                    handleApiFailure(status, json?.message, session);
                }
                return;
            }
            if (json?.conversation) {
                Object.assign(session, {
                    title: json.conversation.title,
                    status: json.conversation.status,
                });
            }
            if (json?.message && !session.messages.some((m) => m.id === json.message.id)) {
                session.messages.push(json.message);
            }
            renderHistory();
            if (selectedId === session.id) {
                clearBanner();
                renderThread({ scrollToBottom: true });
                updateContext();
                announce('Respuesta recibida.');
            }
        } catch (error) {
            if (!disposed && error?.name !== 'AbortError' && selectedId === session.id) {
                showBanner('No pudimos conectar con el servicio de orientación. Tu mensaje quedó guardado.', { error: true, retry: true });
            }
        } finally {
            generatingSessions.delete(session.id);
            renderHistory();
            if (selectedId === session.id) {
                updateComposer();
            }
        }
    }

    async function send() {
        if (isCurrentSessionBusy()) return;
        const draft = currentDraft();
        draft.text = prompt.value;
        const clean = draft.text.trim();
        if (!clean) return;

        clearBanner();
        isSubmitting = true;
        updateComposer();

        let session = currentSession();
        try {
            if (!session) {
                const { ok, status, json } = await apiFetch(baseUrl, {
                    method: 'POST',
                    body: JSON.stringify({ mode: draft.mode, content: clean }),
                });
                if (disposed) return;
                if (!ok) {
                    handleApiFailure(status, json?.message);
                    return;
                }
                session = normalizeLexaSession({
                    ...json.conversation,
                    messages: json.message ? [json.message] : [],
                    loaded: true,
                });
                sessions.unshift(session);
                selectedId = session.id;
                welcomeDraft = createDraft(draft.mode);
            } else {
                const { ok, status, json } = await apiFetch(`${baseUrl}/${session.id}/mensajes`, {
                    method: 'POST',
                    body: JSON.stringify({ content: clean }),
                });
                if (disposed) return;
                if (!ok) {
                    handleApiFailure(status, json?.message, session);
                    return;
                }
                if (json?.message) {
                    session.messages.push(json.message);
                }
                session.draft.text = '';
            }

            renderThread({ scrollToBottom: true });
            renderComposer();
            renderHistory();
            announce('Consulta guardada.');
        } catch (error) {
            if (!disposed && error?.name !== 'AbortError') {
                showBanner('No pudimos guardar tu consulta. Verifica tu conexión e inténtalo nuevamente.', { error: true });
            }
            return;
        } finally {
            isSubmitting = false;
            updateComposer();
        }

        if (session && !disposed) {
            await generateForSession(session);
            if (!disposed && selectedId === session.id) {
                focusPrompt();
            }
        }
    }

    async function loadMoreSessions() {
        if (!sessionsNextCursor || isLoadingSessions || isSubmitting) return;
        abortActiveSessionsLoad();
        const controller = new AbortController();
        activeSessionsController = controller;
        controllers.add(controller);
        isLoadingSessions = true;
        try {
            const url = new URL(baseUrl, window.location.origin);
            url.searchParams.set('cursor', sessionsNextCursor);
            const { ok, status, json } = await apiFetch(url.toString(), { method: 'GET', signal: controller.signal });
            if (disposed || controller.signal.aborted) return;
            if (!ok) {
                handleApiFailure(status, json?.message);
                return;
            }
            for (const raw of json?.conversations || []) {
                if (!sessions.some((s) => s.id === String(raw.id))) {
                    sessions.push(normalizeLexaSession(raw));
                }
            }
            sessionsNextCursor = json?.next_cursor || null;
            renderHistory();
        } catch (error) {
            if (!disposed && !controller.signal.aborted && error?.name !== 'AbortError') {
                showBanner('No pudimos cargar más consultas.', { error: true });
            }
        } finally {
            controllers.delete(controller);
            if (activeSessionsController === controller) {
                activeSessionsController = null;
                isLoadingSessions = false;
            }
        }
    }

    async function confirmDeleteSession() {
        const session = currentSession();
        if (!session || isCurrentSessionBusy()) return;
        isSubmitting = true;
        updateComposer();
        try {
            const { ok, status, json } = await apiFetch(`${baseUrl}/${session.id}`, { method: 'DELETE' });
            if (disposed) return;
            if (!ok) {
                if (deleteDialog.open) deleteDialog.close();
                handleApiFailure(status, json?.message, session);
                return;
            }
            const idx = sessions.findIndex((s) => s.id === session.id);
            if (idx >= 0) sessions.splice(idx, 1);
            selectedId = null;
            if (deleteDialog.open) deleteDialog.close();
            renderThread();
            renderComposer();
            renderHistory();
            clearBanner();
            announce('Conversación eliminada.');
        } catch (error) {
            if (!disposed && error?.name !== 'AbortError') {
                if (deleteDialog.open) deleteDialog.close();
                showBanner('No pudimos eliminar la conversación.', { error: true });
            }
        } finally {
            isSubmitting = false;
            updateComposer();
        }
    }

    listen(deleteDialog, 'close', () => {
        if (!disposed) deleteTrigger?.focus({ preventScroll: true });
    });

    listen(root, 'click', (event) => {
        const target = event.target.closest('button');
        if (!target) return;
        if (target.matches('[data-new-session]')) newSession();
        if (target.matches('[data-session-id]')) selectSession(target.dataset.sessionId);
        if (target.matches('[data-open-history]')) openSidebar(target);
        if (target.matches('[data-close-history]')) closeSidebar();
        if (target.matches('[data-load-more-sessions]')) loadMoreSessions();
        if (target.matches('[data-load-more-messages]')) {
            const session = currentSession();
            if (session && session.nextCursor && !isLoadingThread) {
                loadMessages(session, { cursor: session.nextCursor, append: true });
            }
        }
        if (target.matches('[data-delete-session]')) {
            if (currentSession() && !isCurrentSessionBusy()) {
                deleteTrigger = target;
                deleteDialog.showModal();
            }
        }
        if (target.matches('[data-cancel-delete]')) {
            if (deleteDialog.open) deleteDialog.close();
        }
        if (target.matches('[data-confirm-delete]')) confirmDeleteSession();
        if (target.matches('[data-lexa-banner-retry]')) {
            const session = currentSession();
            if (session && !isCurrentSessionBusy()) generateForSession(session);
        }
        if (target.matches('[data-lexa-banner-reload]')) {
            const session = currentSession();
            if (session) {
                clearBanner();
                loadMessages(session);
            }
        }
        if (target.matches('[data-suggestion]')) {
            if (isCurrentSessionBusy()) return;
            currentDraft().text = target.dataset.suggestion;
            currentDraft().mode = target.dataset.mode;
            renderComposer();
            focusPrompt();
        }
        if (target.matches('[data-copy-message]')) {
            const copyText = target.dataset.copyText || '';
            if (copyText && navigator.clipboard?.writeText) {
                navigator.clipboard.writeText(copyText).then(() => {
                    target.dataset.copied = 'true';
                    setTimeout(() => { delete target.dataset.copied; }, 1600);
                    announce('Respuesta copiada al portapapeles.');
                }).catch(() => {});
            }
        }
    });

    listen($('[data-search]'), 'input', renderHistory);
    listen(prompt, 'input', () => {
        currentDraft().text = prompt.value;
        updateComposer();
    });
    listen(prompt, 'keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            send();
        }
    });
    listen(mode, 'change', () => {
        currentDraft().mode = mode.value;
    });
    listen($('[data-composer]'), 'submit', (event) => {
        event.preventDefault();
        send();
    });

    function resizeViewport() {
        root.style.setProperty('--lexa-viewport', (window.visualViewport?.height || window.innerHeight) + 'px');
        if (document.activeElement === prompt && !currentSession()) {
            requestAnimationFrame(() => prompt.scrollIntoView?.({ block: 'nearest', behavior: 'instant' }));
        }
    }

    listen(window.visualViewport, 'resize', resizeViewport);
    listen(window, 'resize', resizeViewport);

    onBeforeVisit?.(() => {
        abortActiveThreadLoad();
        abortActiveSessionsLoad();
    });

    onCleanup(() => {
        disposed = true;
        abortActiveThreadLoad();
        abortActiveSessionsLoad();
        for (const controller of controllers) {
            controller.abort();
        }
        controllers.clear();
        generatingSessions.clear();
        if (sidebarDialog.open) sidebarDialog.close();
        if (deleteDialog.open) deleteDialog.close();
    });

    resizeViewport();
    renderHistory();
    renderThread();
    renderComposer();

    const incoming = takeHandoff('lexa');
    if (incoming) {
        if (incoming.sessionId && sessions.some((session) => session.id === String(incoming.sessionId))) {
            selectSession(String(incoming.sessionId));
        } else {
            welcomeDraft.text = typeof incoming.text === 'string' ? incoming.text.slice(0, 5000) : '';
            welcomeDraft.mode = data.modes.some((item) => item.id === incoming.mode) ? incoming.mode : 'orientation';
            renderComposer();
            focusPrompt();
        }
    }
}

onPage('[data-lexa]', setupLexa);
