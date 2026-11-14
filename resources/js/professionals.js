import { onPage } from './ui/page.js';

// Search, visibility and pagination are resolved by the server. JavaScript only
// resets a stale municipality when the user chooses a different state.
onPage('[data-professional-directory]', (root, { listen }) => {
    const form = root.querySelector('form');
    if (!form) return;
    listen(root.querySelector('[data-directory-state]'), 'change', () => {
        root.querySelector('[data-directory-municipality]').value = '';
    });
    listen(form, 'submit', (event) => {
        // A clicked specialty replaces the current hidden selection.
        if (event.submitter?.name === 'specialty_id') {
            form.querySelector('[data-selected-specialty]')?.remove();
        }
    });
});
