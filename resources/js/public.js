import { onPage } from './ui/page.js';
import { setupPublicNavigation } from './ui/public-navigation.js';

// Landing gets only this progressive disclosure, without the application bundle.
onPage('[data-public-navigation]', setupPublicNavigation);
