const officialHosts = new Set(['www.diputados.gob.mx', 'dof.gob.mx', 'www.dof.gob.mx', 'sidof.segob.gob.mx', 'www.ordenjuridico.gob.mx']);

export function renderLegalCitations(citations = [], grounding = null) {
    const region = document.createElement('section');
    region.className = 'lexa-citations';
    region.setAttribute('aria-label', 'Fuentes documentales de esta respuesta');
    const notice = document.createElement('p');
    notice.className = 'lexa-grounding-notice';
    const scope = grounding?.state_id ? `Alcance federal y de ${grounding.state_name || 'la entidad seleccionada'}.` : 'Alcance exclusivamente federal; para cuestiones locales, indica la entidad.';
    notice.textContent = !grounding ? 'Respuesta anterior sin trazabilidad documental registrada.' : `${scope} ${grounding.cited_count ? 'Referencias cotejadas con el contexto recuperado; no garantizan por sí solas la conclusión jurídica.' : 'Respuesta sin citas verificables: orientación general, sin fundamento documental suficiente.'}`;
    region.append(notice);
    const list = document.createElement('ul');
    for (const citation of Array.isArray(citations) ? citations.slice(0, 8) : []) {
        let url;
        try { url = new URL(citation.source_url); } catch { continue; }
        if (url.protocol !== 'https:' || !officialHosts.has(url.hostname) || url.username || url.password || url.port) continue;
        const item = document.createElement('li');
        const link = document.createElement('a');
        link.href = url.href;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.textContent = `${citation.instrument} — ${citation.article_ref}${citation.part ? `, parte ${citation.part}` : ''}`;
        const details = document.createElement('p');
        const jurisdiction = citation.jurisdiction_scope === 'state' ? (citation.state_name || 'Entidad no indicada') : 'Federal';
        details.textContent = `${jurisdiction}. Versión: ${citation.version_key || 'no registrada'}. Reforma: ${citation.last_reformed_at || 'no registrada'}. Vigencia revisada: ${citation.valid_from || 'inicio desconocido'} a ${citation.valid_to || 'sin fecha final registrada'}. Recuperada: ${citation.retrieved_at || 'sin fecha'}.`;
        const warning = document.createElement('p');
        warning.textContent = citation.validity_confirmed ? 'Aplicabilidad revisada al recuperar la fuente; no implica confirmación de vigencia actual.' : 'Advertencia: vigencia no confirmada.';
        item.append(link, details, warning);
        list.append(item);
    }
    region.append(list);
    return region;
}
