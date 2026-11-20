import { onPage } from './ui/page.js';
import { validateAttachment, formatBytes } from './messages/attachments.js';
import { createSendAttempt } from './messages/attempt.js';
import { createPoller } from './messages/polling.js';
import { localizeMessageTimes } from './messages/dates.js';

export function setupMessages(root, { listen, onCleanup, onBeforeVisit, isVisiting = () => false }) {
    const $ = (selector) => root.querySelector(selector);
    const history = $('[data-history]');
    const form = $('[data-composer]');
    const info = $('[data-information]');
    const dialog = $('[data-info-dialog]');
    const desktop = matchMedia('(min-width:1200px)');
    const requests = new AbortController();
    let pollRequests = new AbortController();
    const attempt = createSendAttempt();
    let revoked = false;
    let disposed = false;
    let sending = false;
    let uploading = false;
    let uploadAmbiguous = false;
    let refreshing = false;
    let readGeneration = 0;
    let canSend = root.dataset.canSend === 'true';
    let opener;
    const viewport = () => root.style.setProperty('--messages-viewport', `${window.visualViewport?.height || innerHeight}px`);
    listen(window.visualViewport, 'resize', viewport);
    listen(window, 'resize', viewport);
    viewport();
    localizeMessageTimes(root);
    if (!form) return;
    const input = $('[data-draft]');
    const uploadForm = $('[data-upload-form]');
    const status = $('[data-send-status]');
    const pollStatus = $('[data-poll-status]');
    const rules = JSON.parse($('[data-file-rules]').textContent);
    const recovery = $('[data-messaging-recovery]');
    const retry = recovery.querySelector('[data-read-retry]');
    const access = recovery.querySelector('[data-recovery-access]');
    const pollUrl = root.dataset.pollUrl;
    const conversationId = pollUrl?.match(/\/mensajes\/([1-9][0-9]*)\/actualizaciones(?:[?#]|$)/)?.[1];
    const csrf = form.querySelector('[name="_token"]');
    const securityToken = csrf?.value;
    const applied = new Map();
    const say = (message) => { if (!revoked && !disposed) status.textContent = message; };
    const atEnd = () => history.scrollHeight - history.scrollTop - history.clientHeight < 80;
    function controls() {
        if (revoked || disposed) return;
        input.readOnly = !!attempt.current() || uploadAmbiguous;
        input.disabled = !canSend;
        form.querySelector('[data-send]').disabled = !canSend || sending || uploading || uploadAmbiguous;
        form.querySelector('[data-send]').setAttribute('aria-label', attempt.current() ? 'Reintentar el mismo envío' : 'Enviar mensaje');
        form.querySelectorAll('[name="attachment_ids[]"]').forEach(item => { item.disabled = !canSend || !!attempt.current() || sending || uploading || uploadAmbiguous; });
        uploadForm.querySelectorAll('input,button').forEach(item => { if (item.type !== 'hidden') item.disabled = !canSend || !!attempt.current() || sending || uploading || uploadAmbiguous; });
        root.querySelectorAll('[data-prepared-id] button').forEach(item => { item.disabled = !!attempt.current() || sending || uploading || uploadAmbiguous; });
    }
    function fragment(html) {
        const template = document.createElement('template');
        template.innerHTML = html; // Same-origin Blade fragments, with escaped DTO fields.
        return template.content;
    }
    function replaceFragment(key, node, html) {
        const previous = applied.get(key);
        if (previous?.node === node && previous.html === html) return false;
        node.replaceChildren(fragment(html));
        applied.set(key, { node, html }); // Record applied HTML, never a deferred response.
        return true;
    }
    function abortPollOnVisit() {
        if (revoked || disposed) return;
        readGeneration++;
        poller.pause();
        pollRequests.abort();
        pollRequests = new AbortController();
    }
    function dispose() {
        disposed = true;
        applied.clear();
        poller.stop();
        pollRequests.abort();
        requests.abort();
        if (dialog?.open) dialog.close();
    }
    function currentContext() {
        if (root.isConnected && root.dataset.pollUrl === pollUrl && $('[data-composer]') === form && $('[data-history]') === history && csrf?.value === securityToken) return true;
        dispose();
        return false;
    }
    function pauseReads(statusCode) {
        if (revoked || disposed) return;
        readGeneration++;
        poller.pause();
        recovery.hidden = false;
        recovery.querySelector('[data-recovery-title]').textContent = 'La actualización está en pausa';
        recovery.querySelector('[data-recovery-message]').textContent = statusCode === 429
            ? 'Se alcanzó el límite de solicitudes. Espera antes de volver a consultar. Conservamos el historial accesible y el borrador; no reenviamos mensajes ni archivos.'
            : statusCode === 409 ? 'El estado cambió. Consulta la versión actual antes de decidir qué hacer. Conservamos el borrador y no reenviamos mensajes ni archivos.'
            : 'No pudimos consultar la conversación. Conservamos el historial accesible y el borrador. Consulta de nuevo cuando quieras; esta acción no envía mensajes ni archivos.';
        retry.hidden = false;
        access.hidden = true;
    }
    function revoke(statusCode) {
        if (revoked || disposed) return;
        revoked = true;
        applied.clear();
        canSend = false;
        poller.stop();
        pollRequests.abort();
        requests.abort();
        attempt.rejected();
        input.value = '';
        root.querySelectorAll('input').forEach(field => { field.value = ''; field.checked = false; });
        // Empty held nodes as well as the visible tree, including dialogs and drafts.
        history.replaceChildren();
        $('[data-conversation-list]').replaceChildren();
        $('[data-information-body]')?.replaceChildren();
        info.replaceChildren();
        if (dialog.open) dialog.close();
        form.replaceChildren();
        uploadForm.replaceChildren();
        root.querySelector('.messages-chat-header')?.replaceChildren();
        delete root.dataset.pollUrl;
        recovery.hidden = false;
        const sessionLost = statusCode === 401 || statusCode === 419;
        recovery.querySelector('[data-recovery-title]').textContent = sessionLost ? 'Necesitamos comprobar tu sesión' : 'La conversación no está disponible';
        recovery.querySelector('[data-recovery-message]').textContent = sessionLost
            ? 'Retiramos el contenido privado. Revisa tu acceso para continuar desde la bandeja de Mensajes. Ningún mensaje se reenviará automáticamente.'
            : 'Retiramos el contenido de esta conversación. Puedes consultar tu bandeja para ver las conversaciones disponibles.';
        retry.hidden = true;
        access.hidden = false;
        if (sessionLost) {
            // The old identity and its capability links must not remain on screen.
            document.querySelectorAll('.platform-header, .platform-mobile-nav').forEach(header => { header.replaceChildren(); header.remove(); });
            document.getElementById('app')?.classList.add('public-shell');
        }
        root.replaceChildren(recovery);
        root.classList.add('messages-is-recovery');
        const heading = recovery.querySelector('[data-recovery-title]');
        heading.setAttribute('aria-level', '1');
        heading.tabIndex = -1;
        heading.focus();
    }
    const errorText = (statusCode) => statusCode === 401 || statusCode === 419 ? 'Tu sesión venció. Recarga la página e inicia sesión.'
        : statusCode === 404 || statusCode === 403 ? 'Esta operación ya no está disponible por acceso, bloqueo o estado de cuenta.'
        : statusCode === 429 ? 'Espera un momento antes de volver a intentarlo.'
        : statusCode === 422 ? 'Revisa el texto y los archivos. Conserva el identificador y el contenido original del envío; recarga para revisar un identificador ya utilizado.'
        : 'No pudimos confirmar la operación. Reintenta el mismo envío.';
    async function post(target, body) {
        if (!currentContext()) throw new DOMException('Request cancelled', 'AbortError');
        const response = await fetch(target, { method: 'POST', body, credentials: 'same-origin', signal: requests.signal, redirect: 'error',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        if (revoked || disposed || requests.signal.aborted || !currentContext()) throw new DOMException('Request cancelled', 'AbortError');
        if (!response.ok) {
            if ([401,403,404,419].includes(response.status)) revoke(response.status);
            else if (response.status !== 422) pauseReads(response.status);
            const error = new Error(errorText(response.status)); error.status = response.status; throw error;
        }
        const data = await response.json().catch(() => null);
        if (revoked || disposed || requests.signal.aborted || !currentContext()) throw new DOMException('Request cancelled', 'AbortError');
        return data;
    }
    async function update() {
        if (refreshing || revoked || disposed || requests.signal.aborted || pollRequests.signal.aborted || !currentContext()) return;
        refreshing = true;
        const generation = readGeneration;
        const activePollSignal = pollRequests.signal;
        try {
            const response = await fetch(pollUrl, { credentials: 'same-origin', signal: activePollSignal, redirect: 'error', headers: { Accept: 'application/json' } });
            if (revoked || disposed || requests.signal.aborted || activePollSignal.aborted || !currentContext()) return;
            // A paused read generation is still part of this live access context.
            // Definitive denial takes priority over discarding its stale results.
            if ([401,403,404,419].includes(response.status)) { revoke(response.status); return; }
            if (generation !== readGeneration) return;
            if (!response.ok) {
                pauseReads(response.status);
                return;
            }
            const data = await response.json();
            if (revoked || disposed || requests.signal.aborted || activePollSignal.aborted || generation !== readGeneration || !currentContext()) return;
            if (!data || !conversationId || String(data.conversation_id) !== conversationId || typeof data.history_html !== 'string' || typeof data.inbox_html !== 'string' || typeof data.information_html !== 'string' || typeof data.can_send !== 'boolean') throw new Error('Invalid response');
            const bottom = atEnd();
            if (!history.contains(document.activeElement) && !history.querySelector('details[open]')) {
                const changed = replaceFragment('history', history, data.history_html);
                if (changed && bottom) history.scrollTop = history.scrollHeight;
            }
            const inbox = $('[data-conversation-list]');
            if (!inbox.contains(document.activeElement)) replaceFragment('inbox', inbox, data.inbox_html);
            const information = $('[data-information-body]');
            if (!information.contains(document.activeElement) && !information.querySelector('details[open]')) replaceFragment('information', information, data.information_html);
            localizeMessageTimes(root);
            recovery.hidden = true;
            poller.resume();
            canSend = data.can_send;
            controls();
            pollStatus.textContent = canSend ? '' : 'El envío y la edición no están disponibles. El historial accesible se conserva.';
        } catch (error) {
            if (error.name !== 'AbortError' && !activePollSignal.aborted && generation === readGeneration) pauseReads();
        } finally { refreshing = false; }
    }
    const poller = createPoller({ run: update, active: () => !isVisiting() && root.dataset.historical !== 'true' && document.visibilityState === 'visible' && document.hasFocus() && atEnd() && !history.contains(document.activeElement) && !history.querySelector('details[open]') });
    listen(retry, 'click', async () => {
        retry.disabled = true;
        try { await update(); } finally { if (!revoked && !disposed) retry.disabled = false; }
    });
    poller.start();
    listen(document, 'visibilitychange', () => { if (document.visibilityState === 'visible' && !isVisiting()) poller.wake(); });
    listen(window, 'focus', () => { if (!isVisiting()) poller.wake(); });
    onBeforeVisit?.(abortPollOnVisit);
    listen(document, 'turbo:visit', abortPollOnVisit);
    onCleanup(dispose);
    // A browser history cache can preserve a document without disposing the
    // shared page controller. Do not restore stale private chat content.
    listen(window, 'pagehide', event => {
        if (event.persisted) { dispose(); root.replaceChildren(); }
    });
    listen(window, 'pageshow', event => { if (event.persisted) window.location.reload(); });
    listen(form, 'submit', async event => {
        event.preventDefault();
        if (revoked || disposed || sending || uploading || uploadAmbiguous || !canSend) return;
        const data = attempt.begin({ token: form.elements.client_token.value, content: input.value,
            ids: [...form.querySelectorAll('[name="attachment_ids[]"]:checked')].map(item => item.value) });
        const body = new FormData(form);
        body.set('client_token', data.token); body.set('content', data.content); body.delete('attachment_ids[]');
        data.ids.forEach(id => body.append('attachment_ids[]', id));
        sending = true; controls(); say('Enviando…');
        try {
            const result = await post(form.action, body);
            form.elements.client_token.value = attempt.acknowledge(result);
            input.value = '';
            input.style?.setProperty?.('height', 'auto');
            data.ids.forEach(id => {
                root.querySelectorAll('[data-prepared-id]').forEach(item => { if (item.dataset.preparedId === id) item.remove(); });
                form.querySelectorAll('[name="attachment_ids[]"]').forEach(item => { if (item.value === id) item.closest('label').remove(); });
            });
            say('Mensaje guardado.');
            if (root.dataset.historical === 'true') {
                if (!isVisiting()) window.location.assign(result.redirect);
                return;
            }
            history.scrollTop = history.scrollHeight;
            await update();
        } catch (error) {
            if (error.name === 'AbortError' || revoked || disposed) return;
            if (error instanceof TypeError || error.message === 'Invalid acknowledgement' || error.message === 'Invalid response') pauseReads();
            if (error.status === 422) attempt.rejected();
            say(error instanceof TypeError ? 'No se pudo confirmar el envío. Pulsa Reintentar; se conservarán el identificador, texto y archivos originales.' : error.message === 'Invalid acknowledgement' ? 'No se pudo confirmar el envío. Reintenta la misma operación.' : error.message);
        } finally { sending = false; controls(); }
    });
    listen(input, 'input', () => { input.style.height = 'auto'; input.style.height = `${Math.min(input.scrollHeight,128)}px`; });
    listen(uploadForm, 'submit', async event => {
        event.preventDefault();
        if (revoked || disposed || uploading || sending || attempt.current() || uploadAmbiguous || !canSend) return;
        const files = [...uploadForm.querySelector('[data-attach]').files];
        const staged = [...root.querySelectorAll('[data-prepared-id]')].map(() => ({ size: 0 }));
        const errors = [];
        for (const file of files) { const error = validateAttachment(file, staged, rules); if (error) errors.push(error); else staged.push(file); }
        const errorBox = $('[data-file-error]'); errorBox.textContent = errors.join(' '); errorBox.hidden = !errors.length;
        if (errors.length) return;
        const body = new FormData(uploadForm);
        uploading = true; controls(); say('Adjuntando archivos…');
        try {
            const data = await post(uploadForm.action, body);
            if (!Array.isArray(data?.attachments)) throw new Error('Invalid response');
            for (const file of data.attachments) {
                const row = document.createElement('div'); row.className = 'messages-prepared-file'; row.dataset.preparedId = String(file.id);
                const text = document.createElement('span'); text.textContent = `${file.name} · ${formatBytes(file.size_bytes)}`;
                const remove = document.createElement('form'); remove.method = 'POST'; remove.action = file.remove_url;
                for (const [name,value] of [['_token',form.querySelector('[name="_token"]').value],['_method','DELETE']]) { const field = document.createElement('input'); field.type = 'hidden'; field.name = name; field.value = value; remove.append(field); }
                const button = document.createElement('button'); button.className = 'messages-action'; button.textContent = 'Retirar del borrador'; remove.append(button); row.append(text,remove); $('[data-composer-attachments]').append(row);
                const label = document.createElement('label'); label.className = 'messages-file-choice'; const field = document.createElement('input'); field.type = 'checkbox'; field.name = 'attachment_ids[]'; field.value = file.id; field.checked = true; label.append(field,document.createTextNode(` Enviar ${file.name}`)); $('[data-send-attachments]').append(label);
            }
            uploadForm.reset(); uploadForm.closest('details').open = false; say('Archivos listos para enviar.');
        } catch (error) {
            if (error.name === 'AbortError' || revoked || disposed) return;
            if (error instanceof TypeError || error.message === 'Invalid acknowledgement' || error.message === 'Invalid response') pauseReads();
            if (error.status !== 422 && error.status !== 429) uploadAmbiguous = true;
            say('No se pudo confirmar la carga. Recarga para recuperar los archivos preparados antes de volver a adjuntar.');
        } finally { uploading = false; controls(); }
    });
    listen(root, 'submit', async event => {
        const remove = event.target.closest('[data-prepared-id] form');
        if (!remove) return;
        event.preventDefault();
        if (revoked || disposed || !canSend || attempt.current() || sending || uploading || uploadAmbiguous) return;
        const row = remove.closest('[data-prepared-id]');
        const id = row.dataset.preparedId;
        uploading = true; controls();
        try {
            await post(remove.action,new FormData(remove)); row.remove();
            form.querySelectorAll('[name="attachment_ids[]"]').forEach(item => { if (item.value === id) item.closest('label').remove(); });
            say('Archivo retirado del borrador.');
        } catch (error) { if (error.name !== 'AbortError') { pauseReads(); say('No se pudo confirmar la retirada. Recarga para consultar el borrador.'); } }
        finally { uploading = false; controls(); }
    });
    listen(root, 'click', event => {
        if (event.target.closest('[data-open-info]')) {
            if (desktop.matches) { info.querySelector('h2').focus(); return; }
            opener = event.target.closest('button'); dialog.append(info); dialog.showModal();
        }
        if (event.target.closest('[data-close-info]')) dialog.close();
    });
    listen(dialog, 'close', () => { if (revoked || disposed) return; root.querySelector('.messages-layout')?.append(info); if (opener?.isConnected) opener.focus(); });
    listen(desktop, 'change', () => { if (desktop.matches && dialog.open) dialog.close(); });
    history.scrollTop = history.scrollHeight;
    info.querySelector('h2').tabIndex = -1;
    controls();
}

onPage('[data-messaging]', setupMessages);
