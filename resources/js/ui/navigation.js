import { visit, config } from '@hotwired/turbo';
import { onPage, isVisiting, setupNavigationFeedback } from './page.js';

// Laravel continues handling submissions, validation and downloads normally.
config.forms.mode = 'off';
config.drive.progressBarDelay = 80;

export function navigate(url) {
    visit(url);
}

export { onPage, isVisiting, setupNavigationFeedback };

if (typeof document !== 'undefined' && typeof window !== 'undefined') {
    setupNavigationFeedback(document, window);
}
