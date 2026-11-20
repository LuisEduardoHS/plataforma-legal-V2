import './bootstrap';
import './public';
import Alpine from 'alpinejs';
import { onPage, loadOnPage } from './ui/page';
import { setupAccountMenu } from './ui/account-menu';
import { setupDialog } from './ui/dialog';
import { setupValidation } from './ui/validation';
import './ui/navigation';
import './password-reset';

window.Alpine = Alpine;
Alpine.start();
onPage('[data-account-menu]', setupAccountMenu);
onPage('[data-dialog-root]', setupDialog);
onPage('[data-validation-summary]', setupValidation);

loadOnPage('[data-home]', () => import('./home'));
loadOnPage('[data-community]', () => import('./community'));
loadOnPage('[data-messaging]', () => import('./messages'));
loadOnPage('[data-lexa]', () => import('./lexa'));
loadOnPage('[data-professional-directory]', () => import('./professionals'));
loadOnPage('[data-profile-location], [data-help]', () => import('./personal'));
loadOnPage('[data-save-form]', () => import('./saved/library'));
loadOnPage('[data-editorial-form]', () => import('./editorial'));
