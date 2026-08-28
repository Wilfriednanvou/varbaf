import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                // Le portail public.
                'resources/css/app.css',
                'resources/js/app.js',

                // Le panneau d'administration. Deux feuilles distinctes
                // et non une : le portail et le panneau n'ont ni les
                // mêmes composants ni la même base — fondre les deux
                // ferait porter à chaque visiteur du site vitrine le
                // poids du CSS de Filament.
                'resources/css/filament/admin/theme.css',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
