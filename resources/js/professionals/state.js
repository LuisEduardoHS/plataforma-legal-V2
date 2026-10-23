export function normalizeSearch(value) {
    return String(value ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('es').trim();
}

export function matchingProfiles(profiles, { query = '', specialty = 'all', dismissed = [] } = {}) {
    const terms = normalizeSearch(query).split(/\s+/).filter(Boolean);
    const hidden = new Set(dismissed);
    return Object.values(profiles).filter((profile) => {
        if (hidden.has(profile.id) || (specialty !== 'all' && specialty !== profile.specialty_id)) return false;
        const text = normalizeSearch([profile.name, profile.specialty, profile.location].join(' '));
        return terms.every((term) => text.includes(term));
    });
}

export function sectionResults(section, matches, { query = '', specialty = 'all', expanded = false } = {}) {
    const matchingIds = new Set(matches.map((profile) => profile.id));
    const ids = [...new Set(section.profile_ids)].filter((id) => matchingIds.has(id));
    const filtering = Boolean(normalizeSearch(query)) || specialty !== 'all';
    return {
        ids: filtering || expanded ? ids : ids.slice(0, section.limit),
        total: ids.length,
        canExpand: !filtering && ids.length > section.limit,
    };
}
