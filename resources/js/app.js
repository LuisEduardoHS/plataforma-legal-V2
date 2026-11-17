import './bootstrap';
import Alpine from 'alpinejs';
import { onPage } from './ui/page';
import { setupAccountMenu } from './ui/account-menu';
import './ui/navigation';
import './home';
import './community';
import './messages';
import './lexa';
import './professionals';
import './personal';
import './saved/library';
import './editorial';
import './password-reset';

window.Alpine = Alpine;
Alpine.start();
onPage('[data-account-menu]', setupAccountMenu);
