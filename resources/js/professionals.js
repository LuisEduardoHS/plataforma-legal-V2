import { onPage, navigate } from './ui/navigation.js';
import { matchingProfiles, sectionResults, normalizeSearch } from './professionals/state.js';
import { writeHandoff } from './ui/handoff.js';


onPage('[data-professionals]', (root, { listen, onCleanup }) => {
    const $ = (selector) => root.querySelector(selector);
    const data = JSON.parse($('[data-directory-data]').textContent);
    const search = $('[data-professional-search]');
    const dialog = $('[data-profile-dialog]');
    const state = { query: '', specialty: 'all', dismissed: [], expanded: new Set() };
    const status = $('[data-directory-status]');
    let activeProfile = null;
    let opener = null;
    let previousOverflow = '';
    let searchTimer;

    if (data.state !== 'ready') {
        search.disabled = true;
        for (const filter of root.querySelectorAll('[data-specialty]')) filter.disabled = true;
    }

    function render(announce = true) {
        if (data.state !== 'ready') return;
        const matches = matchingProfiles(data.profiles, state);
        const filtering = Boolean(normalizeSearch(state.query)) || state.specialty !== 'all';
        for (const section of data.sections) {
            const element = root.querySelector(`[data-professional-section="${section.id}"]`);
            const result = sectionResults(section, matches, { ...state, expanded: state.expanded.has(section.id) });
            const visible = new Set(result.ids);
            for (const card of element.querySelectorAll('[data-professional-card]')) card.hidden = !visible.has(card.dataset.professionalCard);
            element.hidden = result.total === 0;
            const expand = element.querySelector('[data-expand-section]');
            expand.hidden = !result.canExpand;
            expand.setAttribute('aria-expanded', String(state.expanded.has(section.id)));
            expand.querySelector('span').textContent = state.expanded.has(section.id) ? 'Ver menos' : 'Ver todo';
        }
        $('[data-directory-empty]').hidden = matches.length > 0;
        $('[data-empty-title]').textContent = state.dismissed.length && !filtering ? 'No quedan perfiles visibles' : 'No encontramos perfiles';
        $('[data-empty-message]').textContent = state.dismissed.length && !filtering ? 'Puedes restaurar las recomendaciones que ocultaste.' : 'Prueba con otro nombre, especialidad o ciudad.';
        if (!Object.keys(data.profiles).length) {
            $('[data-empty-title]').textContent = 'Aún no hay perfiles disponibles';
            $('[data-empty-message]').textContent = 'Cuando haya profesionales en el directorio, podrás encontrarlos aquí.';
        }
        $('[data-reset-filters]').hidden = !filtering;
        $('[data-restore-empty]').hidden = !state.dismissed.length;
        $('[data-clear-search]').hidden = !search.value;
        $('[data-result-summary]').hidden = !filtering;
        $('[data-result-summary]').textContent = `${matches.length} ${matches.length === 1 ? 'profesional encontrado' : 'profesionales encontrados'}`;
        $('[data-dismiss-feedback]').hidden = !state.dismissed.length;
        if (state.dismissed.length) {
            const last = data.profiles[state.dismissed.at(-1)];
            $('[data-dismiss-message]').textContent = `Ocultaste a ${last.name} en esta página.`;
        }
        for (const filter of root.querySelectorAll('[data-specialty]')) filter.setAttribute('aria-pressed', String(filter.dataset.specialty === state.specialty));
        if (announce) status.textContent = `${matches.length} ${matches.length === 1 ? 'profesional disponible' : 'profesionales disponibles'}.`;
    }

    function resetFilters() {
        clearTimeout(searchTimer);
        state.query = '';
        state.specialty = 'all';
        search.value = '';
        render();
        search.focus({ preventScroll: true });
    }

    function openProfile(id, trigger) {
        if (!Object.hasOwn(data.profiles, id)) return;
        const profile = data.profiles[id];
        if (!profile || data.state !== 'ready') return;
        activeProfile = profile;
        opener = trigger || root.querySelector(`[data-open-profile="${id}"]:not([hidden])`);
        $('[data-detail-initials]').textContent = profile.initials;
        $('[data-detail-role]').textContent = profile.role;
        $('#professional-detail-title').textContent = profile.name;
        $('#professional-detail-specialty').textContent = profile.specialty;
        $('[data-detail-location]').textContent = profile.location;
        $('[data-detail-verification]').textContent = profile.verified ? 'Perfil verificado' : 'Perfil profesional';
        $('[data-detail-bio]').textContent = profile.bio;
        $('[data-detail-languages]').textContent = profile.languages.join(' · ');
        $('[data-detail-modalities]').textContent = profile.modalities.join(' · ');
        $('[data-detail-topics]').replaceChildren(...profile.topics.map((topic) => {
            const item = document.createElement('li');
            item.textContent = topic;
            return item;
        }));
        $('[data-detail-example]').hidden = !data.is_example;
        $('[data-detail-conversation]').hidden = !profile.conversation_url;
        if (profile.conversation_url) $('[data-detail-conversation]').href = profile.conversation_url;
        else $('[data-detail-conversation]').removeAttribute('href');
        $('[data-prepare-consultation]').href = data.lexa_url;
        $('[data-copy-status]').textContent = '';
        $('[data-detail-error]').hidden = true;
        if (!dialog.open) {
            previousOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
            dialog.showModal();
        }
        dialog.scrollTop = 0;
        $('#professional-detail-title').focus({ preventScroll: true });
    }

    function syncHash() {
        const id = location.hash.startsWith('#perfil-') ? location.hash.slice(8) : null;
        if (id && Object.hasOwn(data.profiles, id)) openProfile(id);
        else if (dialog.open) dialog.close();
    }

    search.addEventListener('input', () => {
        state.query = search.value;
        onCleanup(() => clearTimeout(searchTimer));
    render(false);
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            const count = matchingProfiles(data.profiles, state).length;
            status.textContent = `${count} ${count === 1 ? 'profesional encontrado' : 'profesionales encontrados'}.`;
        }, 250);
    });

    root.addEventListener('click', async (event) => {
        const profileLink = event.target.closest('[data-open-profile]');
        if (profileLink && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey && event.button === 0) {
            event.preventDefault();
            const id = profileLink.dataset.openProfile;
            history.pushState(history.state, '', `#perfil-${id}`);
            openProfile(id, profileLink);
        }
        const prepare = event.target.closest('[data-prepare-consultation]');
        if (prepare && activeProfile) {
            event.preventDefault();
            try {
                writeHandoff('lexa', { text: `Quiero preparar una consulta sobre ${activeProfile.specialty.toLocaleLowerCase('es')}.`, mode: 'orientation' });
                navigate(prepare.href);
            } catch {
                $('[data-detail-error]').textContent = 'No pudimos trasladar la consulta. Intenta de nuevo.';
                $('[data-detail-error]').hidden = false;
            }
        }
        const button = event.target.closest('button');
        if (!button) return;
        if (button.matches('[data-specialty]')) {
            state.specialty = button.dataset.specialty;
            render();
        }
        if (button.matches('[data-clear-search]')) {
            clearTimeout(searchTimer);
            state.query = search.value = '';
            render();
            search.focus({ preventScroll: true });
        }
        if (button.matches('[data-reset-filters]')) resetFilters();
        if (button.matches('[data-expand-section]')) {
            const section = button.closest('[data-professional-section]');
            const id = section.dataset.professionalSection;
            if (state.expanded.has(id)) state.expanded.delete(id);
            else state.expanded.add(id);
            render();
            status.textContent = `${state.expanded.has(id) ? 'Mostrando todos los perfiles' : 'Mostrando menos perfiles'} de ${data.sections.find((section) => section.id === id).title}.`;
        }
        if (button.matches('[data-dismiss-profile]')) {
            const card = button.closest('[data-professional-card]');
            const section = card.closest('[data-professional-section]');
            const id = card.dataset.professionalCard;
            if (!state.dismissed.includes(id)) state.dismissed.push(id);
            onCleanup(() => clearTimeout(searchTimer));
    render(false);
            status.textContent = `Ocultaste a ${data.profiles[id].name}. Puedes deshacerlo o restaurar los perfiles.`;
            const next = Array.from(section.querySelectorAll('[data-professional-card]')).find((card) => !card.hidden);
            (next?.querySelector('[data-open-profile]') || $('[data-undo-dismiss]')).focus({ preventScroll: true });
        }
        if (button.matches('[data-undo-dismiss]')) {
            const id = state.dismissed.pop();
            onCleanup(() => clearTimeout(searchTimer));
    render(false);
            status.textContent = id ? `${data.profiles[id].name} vuelve a aparecer en las recomendaciones.` : '';
            if (!state.dismissed.length) search.focus({ preventScroll: true });
        }
        if (button.matches('[data-restore-dismissed], [data-restore-empty]')) {
            state.dismissed = [];
            onCleanup(() => clearTimeout(searchTimer));
    render(false);
            status.textContent = 'Se restauraron todos los perfiles ocultos en esta página.';
            search.focus({ preventScroll: true });
        }
        if (button.matches('[data-close-profile]')) dialog.close();
        if (button.matches('[data-copy-profile]')) {
            const id = activeProfile?.id;
            const url = new URL(`#perfil-${id}`, location.href).href;
            try {
                await navigator.clipboard.writeText(url);
                if (dialog.open && activeProfile?.id === id) $('[data-copy-status]').textContent = 'Enlace copiado.';
            } catch {
                if (dialog.open && activeProfile?.id === id) $('[data-copy-status]').textContent = 'No pudimos copiarlo. Puedes usar el enlace de la barra de direcciones.';
            }
        }
        if (button.matches('[data-retry]')) location.reload();
    });

    dialog.addEventListener('close', () => {
        document.body.style.overflow = previousOverflow;
        if (location.hash.startsWith('#perfil-')) history.replaceState(history.state, '', location.pathname + location.search);
        if (opener?.getClientRects().length && !opener.closest('[hidden]')) opener.focus({ preventScroll: true });
        else search.focus({ preventScroll: true });
        activeProfile = null;
    });
    dialog.addEventListener('click', (event) => {
        if (event.target !== dialog) return;
        const bounds = dialog.getBoundingClientRect();
        if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) dialog.close();
    });
    listen(window, 'hashchange', syncHash);
    onCleanup(() => clearTimeout(searchTimer));
    render(false);
    syncHash();
    root.dataset.initialized = 'true';
});
