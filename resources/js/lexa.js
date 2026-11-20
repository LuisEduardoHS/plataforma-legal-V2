import { onPage } from './ui/navigation.js';
import { createDraft, matchesSession, normalizeLexaSession, formatApiError } from './lexa/state.js';
import { renderMarkdownToFragment } from './lexa/markdown.js';
import { takeHandoff } from './ui/handoff.js';

onPage('[data-lexa]', (root, { listen, onCleanup }) => {
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

    let selectedId = null;
    let welcomeDraft = createDraft();
    let sidebarTrigger;
    let deleteTrigger;
    let previousOverflow = '';
    let isBusy = false;
    let isLoadingThread = false;
    let activeThreadController = null;

    function currentSession() {
        return sessions.find((session) => session.id === selectedId) || null;
    }

    function currentDraft() {
        return currentSession()?.draft || welcomeDraft;
    }

    function announce(text) {
        $('[data-lexa-status]').textContent = text;
    }

    function clearBanner() {
        banner.hidden = true;
        banner.classList.remove('is-error');
        bannerText.textContent = '';
        bannerRetry.hidden = true;
        bannerReload.hidden = true;
        bannerLogin.hidden = true;
    }

    function showBanner(text, { error = false, retry = false, reload = false, login = false } = {}) {
        bannerText.textContent = text;
        banner.classList.toggle('is-error', error);
        bannerRetry.hidden = !retry;
        bannerReload.hidden = !reload;
        bannerLogin.hidden = !login;
        banner.hidden = false;
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
            button.hidden = !matchesSession(session, query);
            list.append(button);
        }
        $('[data-history-empty]').hidden = sessions.some((s) => matchesSession(s, query));
        loadMoreSessionsBtn.hidden = !sessionsNextCursor || query.trim() !== '';
    }

    function updateComposer() {
        const hasText = prompt.value.trim().length > 0;
        const sendBtn = $('[data-send]');
        sendBtn.disabled = isBusy || !hasText;
        prompt.readOnly = isBusy;
        mode.disabled = isBusy || Boolean(currentSession());
        prompt.style.height = 'auto';
        prompt.style.height = Math.min(prompt.scrollHeight, 180) + 'px';
    }

    function updateContext() {
        const session = currentSession();
        $('[data-context-details]').hidden = !session;
        $('[data-context-title]').textContent = session?.title || '';
        $('[data-context-mode]').textContent = session ? (data.modes.find((item) => item.id === session.mode)?.label || '') : '';
        $('[data-context-count]').textContent = '';
        $('[data-context-files]').replaceChildren();
        $('[data-context-empty]').hidden = false;
    }

    function renderComposer() {
        prompt.value = currentDraft().text;
        mode.value = currentDraft().mode;
        $('[data-file-error]').hidden = true;
        updateContext();
        updateComposer();
    }

    function renderThread({ scrollToBottom = true } = {}) {
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
            $('[data-answer-icon]', article).toggleAttribute('hidden', own);
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
            }
            threadList.append(article);
        }
        if (scrollToBottom) {
            requestAnimationFrame(() => {
                thread.scrollTop = thread.scrollHeight;
            });
        }
    }

    function closeSidebar() {
        if (sidebarDialog.open) sidebarDialog.close();
    }

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

    listen(sidebarBreakpoint, 'change', () => {
        if (sidebarBreakpoint.matches) closeSidebar();
    });

    function focusPrompt() {
        prompt.focus({ preventScroll: true });
        if (!currentSession()) {
            requestAnimationFrame(() => prompt.scrollIntoView({ block: 'nearest', behavior: 'instant' }));
        }
    }

    async function loadMessages(session, { cursor = null, append = false } = {}) {
        if (activeThreadController) {
            activeThreadController.abort();
        }
        const controller = new AbortController();
        activeThreadController = controller;
        controllers.add(controller);
        isLoadingThread = true;
        try {
            const url = new URL(`${baseUrl}/${session.id}/mensajes`, window.location.origin);
            if (cursor) url.searchParams.set('cursor', cursor);
            const { ok, status, json } = await apiFetch(url.toString(), { method: 'GET', signal: controller.signal });
            if (controller.signal.aborted) return;
            if (!ok) {
                handleApiFailure(status, json?.message, session);
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
            if (error?.name !== 'AbortError') {
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
        clearBanner();
        selectedId = String(id);
        renderThread();
        renderComposer();
        renderHistory();
        closeSidebar();
        const session = currentSession();
        if (session && !session.loaded) {
            await loadMessages(session);
        }
        requestAnimationFrame(focusPrompt);
    }

    function newSession() {
        clearBanner();
        selectedId = null;
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
            showBanner('Tu consulta quedó guardada. La generación automática con IA está desactivada en esta configuración.', { error: false });
            return;
        }
        isBusy = true;
        updateComposer();
        showBanner('Generando respuesta…', { error: false });
        announce('Generando respuesta.');
        try {
            const attemptToken = crypto.randomUUID();
            const { ok, status, json } = await apiFetch(`${baseUrl}/${session.id}/generar`, {
                method: 'POST',
                body: JSON.stringify({ attempt_token: attemptToken }),
            });
            if (!ok) {
                handleApiFailure(status, json?.message, session);
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
            clearBanner();
            if (selectedId === session.id) {
                renderThread({ scrollToBottom: true });
                renderHistory();
            }
            announce('Respuesta recibida.');
        } catch (error) {
            if (error?.name !== 'AbortError') {
                showBanner('No pudimos conectar con el servicio de orientación. Tu mensaje quedó guardado.', { error: true, retry: true });
            }
        } finally {
            isBusy = false;
            updateComposer();
        }
    }

    async function send() {
        if (isBusy) return;
        const draft = currentDraft();
        draft.text = prompt.value;
        const clean = draft.text.trim();
        if (!clean) return;

        clearBanner();
        isBusy = true;
        updateComposer();

        let session = currentSession();
        try {
            if (!session) {
                const { ok, status, json } = await apiFetch(baseUrl, {
                    method: 'POST',
                    body: JSON.stringify({ mode: draft.mode, content: clean }),
                });
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
            if (error?.name !== 'AbortError') {
                showBanner('No pudimos guardar tu consulta. Verifica tu conexión e inténtalo nuevamente.', { error: true });
            }
            return;
        } finally {
            isBusy = false;
            updateComposer();
        }

        if (session) {
            await generateForSession(session);
            focusPrompt();
        }
    }

    async function loadMoreSessions() {
        if (!sessionsNextCursor || isBusy) return;
        try {
            const url = new URL(baseUrl, window.location.origin);
            url.searchParams.set('cursor', sessionsNextCursor);
            const { ok, status, json } = await apiFetch(url.toString(), { method: 'GET' });
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
            if (error?.name !== 'AbortError') {
                showBanner('No pudimos cargar más consultas.', { error: true });
            }
        }
    }

    async function confirmDeleteSession() {
        const session = currentSession();
        if (!session || isBusy) return;
        isBusy = true;
        updateComposer();
        try {
            const { ok, status, json } = await apiFetch(`${baseUrl}/${session.id}`, { method: 'DELETE' });
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
            if (error?.name !== 'AbortError') {
                if (deleteDialog.open) deleteDialog.close();
                showBanner('No pudimos eliminar la conversación.', { error: true });
            }
        } finally {
            isBusy = false;
            updateComposer();
        }
    }

    deleteDialog.addEventListener('close', () => {
        deleteTrigger?.focus({ preventScroll: true });
    });

    root.addEventListener('click', (event) => {
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
            if (currentSession()) {
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
            if (session && !isBusy) generateForSession(session);
        }
        if (target.matches('[data-lexa-banner-reload]')) {
            const session = currentSession();
            if (session) {
                clearBanner();
                loadMessages(session);
            }
        }
        if (target.matches('[data-suggestion]')) {
            if (isBusy) return;
            currentDraft().text = target.dataset.suggestion;
            currentDraft().mode = target.dataset.mode;
            renderComposer();
            focusPrompt();
        }
        if (target.matches('[data-copy-message]')) {
            const copyText = target.dataset.copyText || '';
            if (copyText && navigator.clipboard?.writeText) {
                navigator.clipboard.writeText(copyText).then(() => announce('Respuesta copiada al portapapeles.')).catch(() => {});
            }
        }
    });

    $('[data-search]').addEventListener('input', renderHistory);
    prompt.addEventListener('input', () => {
        currentDraft().text = prompt.value;
        updateComposer();
    });
    prompt.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            send();
        }
    });
    mode.addEventListener('change', () => {
        currentDraft().mode = mode.value;
    });
    $('[data-composer]').addEventListener('submit', (event) => {
        event.preventDefault();
        send();
    });

    function resizeViewport() {
        root.style.setProperty('--lexa-viewport', (window.visualViewport?.height || window.innerHeight) + 'px');
        if (document.activeElement === prompt && !currentSession()) {
            requestAnimationFrame(() => prompt.scrollIntoView({ block: 'nearest', behavior: 'instant' }));
        }
    }

    listen(window.visualViewport, 'resize', resizeViewport);
    listen(window, 'resize', resizeViewport);

    onCleanup(() => {
        for (const controller of controllers) {
            controller.abort();
        }
        controllers.clear();
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
});
