import { onPage } from './ui/page.js';
import { normalize } from './saved/store.js';
import { setupProfileLocation } from './profiles/location.js';

onPage('[data-profile-location]', setupProfileLocation);

onPage('[data-help]', (root) => {
    root.querySelector('[data-help-tools]').hidden = false;
    const search = root.querySelector('[data-help-search]');
    const questions = [...root.querySelectorAll('[data-help-question]')];
    let category = 'all';
    function render() {
        const words = normalize(search.value).split(/\s+/).filter(Boolean);
        let count = 0;
        questions.forEach((item) => {
            item.hidden = (category !== 'all' && item.dataset.category !== category)
                || !words.every((word) => normalize(item.textContent).includes(word));
            if (item.hidden) item.open = false; else count++;
        });
        root.querySelector('[data-help-count]').textContent = `${count} ${count === 1 ? 'pregunta' : 'preguntas'}`;
        root.querySelector('[data-help-empty]').hidden = count > 0;
        root.querySelector('[data-help-guides]').hidden = Boolean(search.value.trim()) || category !== 'all';
        root.querySelectorAll('[data-help-category]').forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.helpCategory === category)));
    }
    search.addEventListener('input', render);
    root.addEventListener('click', (event) => {
        const filter = event.target.closest('[data-help-category]');
        if (filter) { category = filter.dataset.helpCategory; render(); }
        if (event.target.closest('[data-help-reset]')) { search.value = ''; category = 'all'; render(); search.focus(); }
    });
});
