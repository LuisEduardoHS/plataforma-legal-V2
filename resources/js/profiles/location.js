// Keep the live form (including credentials, checkboxes and file inputs) in place.
// Only the public municipality catalog lives in this controller's memory.
export function setupProfileLocation(root, { listen, onCleanup }) {
    const state = root.querySelector('[data-location-state]');
    const municipality = root.querySelector('[data-location-municipality]');
    const catalogForm = root.querySelector('[data-location-form]');
    const profileForm = root.querySelector('[data-profile-edit-form]');
    const load = root.querySelector('[data-location-load]');
    const status = root.querySelector('[data-location-status]');
    const fallback = root.querySelector('[data-location-fallback]');
    const acknowledgement = root.querySelector('[data-location-ack]');
    if (!state || !municipality || !catalogForm || !profileForm || !load || !status) return;

    const catalog = new Map();
    let loadedState = root.dataset.locationUnresolved === 'true' ? null : root.dataset.locationCatalogState;
    if (loadedState !== null) catalog.set(loadedState, [...municipality.options].filter(option => option.value)
        .map(option => ({ id: Number(option.value), name: option.textContent })));
    let pending;
    let generation = 0;
    let disposed = false;
    let ready = true;
    const saveButtons = [...profileForm.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]')];
    const originalDisabled = saveButtons.map(button => button.disabled);

    function setBusy(blocked) {
        ready = !blocked;
        municipality.disabled = blocked;
        profileForm.setAttribute('aria-busy', String(blocked));
        saveButtons.forEach((button, index) => { button.disabled = blocked || originalDisabled[index]; });
    }
    function replaceOptions(items, selected = '') {
        const document = municipality.ownerDocument;
        const options = [document.createElement('option')];
        options[0].value = '';
        options[0].textContent = 'Sin ubicación';
        for (const item of items) {
            const option = document.createElement('option');
            option.value = String(item.id);
            option.textContent = item.name;
            options.push(option);
        }
        municipality.replaceChildren(...options);
        municipality.value = items.some(item => String(item.id) === selected) ? selected : '';
    }
    function showCatalog(id, items, selected) {
        replaceOptions(items, selected);
        loadedState = id;
        root.dataset.locationCatalogState = id;
        setBusy(false);
        status.textContent = !id ? 'Sin estado seleccionado. Puedes guardar sin ubicación.'
            : items.length ? 'Municipios disponibles. Selecciona un municipio válido o guarda sin ubicación.'
                : 'Este estado no tiene municipios en el catálogo. Puedes guardar sin ubicación.';
        load.textContent = 'Cargar municipios';
    }
    async function update() {
        const id = state.value;
        if (disposed || pending?.id === id || (ready && loadedState === id)) return;
        const selected = municipality.value;
        pending?.controller.abort();
        pending = undefined;
        const ticket = ++generation;
        if (!id || catalog.has(id)) {
            showCatalog(id, catalog.get(id) ?? [], selected);
            return;
        }

        const controller = new AbortController();
        pending = { id, controller };
        replaceOptions([]);
        setBusy(true);
        status.textContent = 'Cargando municipios. Tus demás campos se conservan.';
        load.textContent = 'Cargando municipios…';
        try {
            const url = new URL(root.dataset.municipalitiesUrl, window.location.href);
            if (url.origin !== window.location.origin) throw new Error('Unexpected origin');
            url.search = '';
            url.hash = '';
            url.searchParams.set('state_id', id);
            const response = await fetch(url, {
                method: 'GET', headers: { Accept: 'application/json' },
                credentials: 'same-origin', signal: controller.signal, cache: 'no-store',
            });
            if (!response.ok) throw new Error('Catalog unavailable');
            const data = await response.json();
            const ids = new Set();
            if (String(data.state_id) !== id || !Array.isArray(data.municipalities)
                || !data.municipalities.every(item => Number.isSafeInteger(item.id) && item.id > 0
                    && typeof item.name === 'string' && !ids.has(item.id) && ids.add(item.id))) {
                throw new Error('Invalid catalog');
            }
            if (disposed || ticket !== generation || state.value !== id) return;
            catalog.set(id, data.municipalities);
            showCatalog(id, data.municipalities, selected);
        } catch {
            if (disposed || ticket !== generation) return;
            status.textContent = 'No se pudieron cargar los municipios. Tus demás campos se conservan. Reintenta; si tu sesión venció, guarda una copia privada de tu texto antes de volver a entrar.';
            load.textContent = 'Reintentar carga';
            // Saving a disabled select would silently omit municipality_id.
            setBusy(true);
        } finally {
            if (ticket === generation) pending = undefined;
        }
    }

    if (fallback) fallback.hidden = true;
    if (acknowledgement) { acknowledgement.required = false; acknowledgement.disabled = true; }
    load.textContent = 'Cargar municipios';
    status.textContent = 'Al cambiar de estado se cargarán sus municipios sin descartar tus demás campos.';
    listen(state, 'change', update);
    listen(catalogForm, 'submit', event => { event.preventDefault(); update(); });
    listen(profileForm, 'submit', event => {
        if (!ready) { event.preventDefault(); load.focus(); }
    });
    // Turbo snapshots must not treat a pending/failed empty select as a loaded catalog.
    listen(root.ownerDocument, 'turbo:before-cache', () => {
        root.dataset.locationUnresolved = String(!ready);
        generation++;
        pending?.controller.abort();
        pending = undefined;
    });
    onCleanup(() => {
        disposed = true;
        generation++;
        pending?.controller.abort();
        pending = undefined;
        setBusy(false);
    });
    if (loadedState !== state.value) update();
}
