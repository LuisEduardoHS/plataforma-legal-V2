import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/css/components/userHeader.css',
                'resources/css/community.css',
                'resources/js/community.js',
                'resources/css/messages.css',
                'resources/js/messages.js',
                'resources/css/lexa.css',
                'resources/js/lexa.js',
                'resources/css/home.css',
                'resources/js/home.js',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
});
