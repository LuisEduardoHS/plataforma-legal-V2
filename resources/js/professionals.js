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
        if (event.submitter?.name === 'specialty_id') form.querySelector('[data-selected-specialty]')?.remove();
    });
    const categoryRow = root.querySelector('[data-category-row]');
    if (categoryRow) {
        const selectedContainer = categoryRow.querySelector('[data-selected-categories]');
        const checkboxes = Array.from(categoryRow.querySelectorAll('input[name="specialty_ids[]"]'));
        const updateCategories = () => {
            if (!selectedContainer) return;
            selectedContainer.replaceChildren();
            checkboxes.filter((checkbox) => checkbox.checked).forEach((checkbox) => {
                const badge = document.createElement('span');
                badge.className = 'inline-flex min-h-11 items-center rounded-[10px] bg-[var(--professionals-tint)] px-3 py-2 text-xs font-semibold text-dark-accent transition-colors dark:text-accent-text-dark';
                badge.textContent = checkbox.closest('label')?.querySelector('span')?.textContent?.trim() ?? '';
                selectedContainer.append(badge);
            });
        };
        checkboxes.forEach((checkbox) => listen(checkbox, 'change', updateCategories));
    }
});
