import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/landing.css',
                'resources/css/views/login.css',
                'resources/js/app.js',
                'resources/js/public.js',
                'resources/css/components/userHeader.css',
                'resources/css/community.css',
                'resources/css/messages.css',
                'resources/css/lexa.css',
                'resources/css/home.css',
                'resources/css/articles.css',
                'resources/css/library-help.css',
                'resources/css/professionals.css',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
});
