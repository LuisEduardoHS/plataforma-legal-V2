import { visit, config } from '@hotwired/turbo';

// Laravel continues handling submissions, validation and downloads normally.
config.forms.mode = 'off';
config.drive.progressBarDelay = 180;

export function navigate(url) {
    visit(url);
}

export { onPage } from './page.js';

// Keep keyboard navigation oriented after the body changes, without stealing
// focus from a draft or a dialog explicitly opened by a page controller.
document.addEventListener('turbo:load', (event) => {
    if (!event.detail.timing?.visitStart) return;
    if (document.activeElement !== document.body) return;
    document.querySelector('#main-content')?.focus({ preventScroll: true });
});
