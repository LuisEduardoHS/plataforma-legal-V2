import { onPage } from './ui/page.js';
import { validateAttachment, formatBytes } from './messages/attachments.js';
import { createSendAttempt } from './messages/attempt.js';
import { createPoller } from './messages/polling.js';
import { localizeMessageTimes } from './messages/dates.js';

onPage('[data-messaging]', (root, { listen, onCleanup }) => {
    const $ = (selector) => root.querySelector(selector);
    const history = $('[data-history]');
    const form = $('[data-composer]');
    const info = $('[data-information]');
    const dialog = $('[data-info-dialog]');
    const desktop = matchMedia('(min-width:1200px)');
    const requests = new AbortController();
    const attempt = createSendAttempt();
    let sending = false;
    let uploading = false;
    let uploadAmbiguous = false;
    let refreshing = false;
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
    const say = (message) => { status.textContent = message; };
    const atEnd = () => history.scrollHeight - history.scrollTop - history.clientHeight < 80;
    function controls() {
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
    function revoke() {
        canSend = false;
        history.replaceChildren();
        $('[data-conversation-list]').replaceChildren();
        $('[data-information-body]')?.replaceChildren();
        pollStatus.textContent = 'La conversación o sesión ya no está disponible. Vuelve a iniciar sesión.';
        controls();
        poller.stop();
    }
    const errorText = (statusCode) => statusCode === 401 || statusCode === 419 ? 'Tu sesión venció. Recarga la página e inicia sesión.'
        : statusCode === 404 || statusCode === 403 ? 'Esta operación ya no está disponible por acceso, bloqueo o estado de cuenta.'
        : statusCode === 429 ? 'Espera un momento antes de volver a intentarlo.'
        : statusCode === 422 ? 'Revisa el texto y los archivos. Conserva el identificador y el contenido original del envío; recarga para revisar un identificador ya utilizado.'
        : 'No pudimos confirmar la operación. Reintenta el mismo envío.';
    async function post(target, body) {
        const response = await fetch(target, { method: 'POST', body, credentials: 'same-origin', signal: requests.signal,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await response.json().catch(() => null);
        if (!response.ok) {
            if (response.status === 401 || response.status === 403 || response.status === 404) revoke();
            const error = new Error(errorText(response.status)); error.status = response.status; throw error;
        }
        return data;
    }
    async function update() {
        if (refreshing || requests.signal.aborted) return;
        refreshing = true;
        try {
            const response = await fetch(root.dataset.pollUrl, { credentials: 'same-origin', signal: requests.signal, headers: { Accept: 'application/json' } });
            if (!response.ok) {
                if ([401,403,404].includes(response.status)) revoke();
                else pollStatus.textContent = 'La actualización no está disponible. Se reintentará mientras esta conversación esté activa.';
                return;
            }
            const data = await response.json();
            if (typeof data.history_html !== 'string' || typeof data.can_send !== 'boolean') throw new Error('Invalid response');
            const bottom = atEnd();
            if (!history.contains(document.activeElement) && !history.querySelector('details[open]')) {
                history.replaceChildren(fragment(data.history_html));
                if (bottom) history.scrollTop = history.scrollHeight;
            }
            const inbox = $('[data-conversation-list]');
            if (!inbox.contains(document.activeElement)) inbox.replaceChildren(fragment(data.inbox_html));
            const information = $('[data-information-body]');
            if (!information.contains(document.activeElement) && !information.querySelector('details[open]')) information.replaceChildren(fragment(data.information_html));
            localizeMessageTimes(root);
            canSend = data.can_send;
            controls();
            pollStatus.textContent = canSend ? '' : 'El envío y la edición no están disponibles. El historial accesible se conserva.';
        } catch (error) {
            if (error.name !== 'AbortError') pollStatus.textContent = 'Sin actualización por el momento. Se reintentará al recuperar la conexión.';
        } finally { refreshing = false; }
    }
    const poller = createPoller({ run: update, active: () => root.dataset.historical !== 'true' && document.visibilityState === 'visible' && document.hasFocus() && atEnd() && !history.contains(document.activeElement) && !history.querySelector('details[open]') });
    poller.start();
    listen(document, 'visibilitychange', () => { if (document.visibilityState === 'visible') poller.wake(); });
    listen(window, 'focus', () => poller.wake());
    onCleanup(() => { poller.stop(); requests.abort(); if (dialog?.open) dialog.close(); });
    // A browser history cache can preserve a document without disposing the
    // shared page controller. Do not restore stale private chat content.
    listen(window, 'pagehide', event => {
        if (event.persisted) { poller.stop(); requests.abort(); root.replaceChildren(); }
    });
    listen(window, 'pageshow', event => { if (event.persisted) window.location.reload(); });
    listen(form, 'submit', async event => {
        event.preventDefault();
        if (sending || uploading || uploadAmbiguous || !canSend) return;
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
            data.ids.forEach(id => {
                root.querySelectorAll('[data-prepared-id]').forEach(item => { if (item.dataset.preparedId === id) item.remove(); });
                form.querySelectorAll('[name="attachment_ids[]"]').forEach(item => { if (item.value === id) item.closest('label').remove(); });
            });
            say('Mensaje guardado.');
            if (root.dataset.historical === 'true') { window.location.assign(result.redirect); return; }
            history.scrollTop = history.scrollHeight;
            await update();
        } catch (error) {
            if (error.name === 'AbortError') return;
            if (error.status === 422) attempt.rejected();
            say(error instanceof TypeError ? 'No se pudo confirmar el envío. Pulsa Reintentar; se conservarán el identificador, texto y archivos originales.' : error.message === 'Invalid acknowledgement' ? 'No se pudo confirmar el envío. Reintenta la misma operación.' : error.message);
        } finally { sending = false; controls(); }
    });
    listen(input, 'input', () => { input.style.height = 'auto'; input.style.height = `${Math.min(input.scrollHeight,128)}px`; });
    listen(uploadForm, 'submit', async event => {
        event.preventDefault();
        if (uploading || sending || attempt.current() || uploadAmbiguous || !canSend) return;
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
            if (error.name === 'AbortError') return;
            if (error.status !== 422 && error.status !== 429) uploadAmbiguous = true;
            say('No se pudo confirmar la carga. Recarga para recuperar los archivos preparados antes de volver a adjuntar.');
        } finally { uploading = false; controls(); }
    });
    listen(root, 'submit', async event => {
        const remove = event.target.closest('[data-prepared-id] form');
        if (!remove) return;
        event.preventDefault();
        if (attempt.current() || sending || uploading || uploadAmbiguous) return;
        const row = remove.closest('[data-prepared-id]');
        const id = row.dataset.preparedId;
        uploading = true; controls();
        try {
            await post(remove.action,new FormData(remove)); row.remove();
            form.querySelectorAll('[name="attachment_ids[]"]').forEach(item => { if (item.value === id) item.closest('label').remove(); });
            say('Archivo retirado del borrador.');
        } catch (error) { if (error.name !== 'AbortError') say('No se pudo confirmar la retirada. Recarga para consultar el borrador.'); }
        finally { uploading = false; controls(); }
    });
    listen(root, 'click', event => {
        if (event.target.closest('[data-open-info]')) {
            if (desktop.matches) { info.querySelector('h2').focus(); return; }
            opener = event.target.closest('button'); dialog.append(info); dialog.showModal();
        }
        if (event.target.closest('[data-close-info]')) dialog.close();
    });
    listen(dialog, 'close', () => { root.querySelector('.messages-layout')?.append(info); opener?.focus(); });
    listen(desktop, 'change', () => { if (desktop.matches && dialog.open) dialog.close(); });
    history.scrollTop = history.scrollHeight;
    info.querySelector('h2').tabIndex = -1;
    controls();
});
