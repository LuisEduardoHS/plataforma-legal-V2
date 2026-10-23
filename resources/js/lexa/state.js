import { validateAttachment } from '../messages/attachments.js';

export function createDraft(mode = 'orientation') {
    return { text: '', mode, attachments: [] };
}
export function matchesSession(session, query) {
    const normalize = (value) => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('es').trim();
    return normalize(session.title).includes(normalize(query));
}
export function validateContext(file, selected) {
    if (!/\.(jpe?g|png|webp|pdf|docx)$/i.test(file.name)) return 'Usa imágenes JPEG, PNG o WebP, o documentos PDF o DOCX.';
    return validateAttachment(file, selected);
}
export function createSession(text, mode) {
    return { id: crypto.randomUUID(), title: text.trim().replace(/\s+/g, ' ').slice(0, 72) || 'Consulta con contexto', mode, messages: [], draft: createDraft(mode) };
}
export function appendExchange(session, draft, answer) {
    if (!draft.text.trim() && !draft.attachments.length) return false;
    session.mode = draft.mode;
    session.messages.push(
        { id: crypto.randomUUID(), role: 'user', text: draft.text.trim() || 'Quiero conversar sobre el contexto adjunto.', attachments: [...draft.attachments] },
        { id: crypto.randomUUID(), role: 'assistant', text: answer, example: true, attachments: [] },
    );
    // Attachments now belong to message history; their URLs remain usable.
    session.draft = createDraft(draft.mode);
    return true;
}