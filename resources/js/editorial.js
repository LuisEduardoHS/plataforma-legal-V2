import { onPage } from './ui/page.js';
import { validateCommunityFiles } from './community/files.js';

onPage('[data-editorial-form]', (form, { listen }) => {
    const input = form.querySelector('[data-editorial-files]');
    if (!input) return;
    const error = form.querySelector('[data-editorial-file-error]');
    const rules = JSON.parse(form.querySelector('[data-editorial-file-rules]').textContent);
    function validate() {
        const retained = form.querySelectorAll('[name="attachment_ids[]"]:checked').length;
        const errors = validateCommunityFiles([...input.files], rules, retained);
        error.textContent = errors.join(' ');
        error.hidden = errors.length === 0;
        input.setCustomValidity(errors.length ? 'Revisa los archivos seleccionados.' : '');
        return errors.length === 0;
    }
    listen(form, 'change', validate);
    listen(form, 'submit', (event) => { if (!validate()) event.preventDefault(); });
});
