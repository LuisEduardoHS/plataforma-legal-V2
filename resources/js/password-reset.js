import { onPage } from './ui/page.js';
import { parseResetFragment } from './auth/reset-fragment.js';

onPage('body', (root, { listen }) => {
    function hydrate() {
        const { recognized, data } = parseResetFragment(window.location.hash);
        if (!recognized) return;
        const form = root.querySelector('[data-password-reset]');
        if (form && data) {
            form.querySelector('[name="email"]').value = data.email;
            form.querySelector('[name="token"]').value = data.token;
        }
        // Also scrub after an authentication redirect or an invalid crafted fragment.
        window.history.replaceState(null, '', window.location.pathname + window.location.search);
        if (form && !data) form.querySelector('[data-fragment-status]').textContent = 'El enlace no contiene un código válido. Solicita uno nuevo.';
    }
    hydrate();
    listen(window, 'hashchange', hydrate);
});
