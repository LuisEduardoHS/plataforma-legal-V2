export const normalize = (value = '') => String(value).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();

// A server acknowledgement is the only source of a bookmark's new state.
export function savedResponse(ok, status, data) {
    if (!ok) {
        if (status === 401 || status === 419) throw new Error('Tu sesión venció. Recarga la página e inicia sesión.');
        if (status === 404 || status === 403) throw new Error('El contenido ya no está disponible para guardar.');
        if (status === 429) throw new Error('Espera un momento antes de volver a intentarlo.');
        throw new Error('No pudimos actualizar tus guardados. Inténtalo nuevamente.');
    }
    if (!data || typeof data.saved !== 'boolean') throw new Error('No pudimos confirmar el cambio. Recarga la página.');
    return { saved: data.saved, message: data.saved ? 'Contenido añadido a tus guardados.' : 'Contenido retirado de tus guardados.' };
}
