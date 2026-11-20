import { setupAccountMenu } from './account-menu.js';

export function setupPublicNavigation(root, lifecycle) {
    // Share the existing native disclosure's keyboard, outside click and Turbo cleanup.
    setupAccountMenu(root, lifecycle);
    const trigger = root.querySelector('[data-account-trigger]');
    const sync = () => trigger.setAttribute('aria-expanded', String(root.open));
    lifecycle.listen(root, 'toggle', sync);
    lifecycle.onCleanup(sync);
    sync();
}
