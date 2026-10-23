import { createLibrary } from './store.js';
export const library = createLibrary(() => localStorage, () => window.dispatchEvent(new Event('saved:change')));
window.savedLibrary = library;
