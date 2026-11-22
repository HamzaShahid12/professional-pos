import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: { base: null, includeAbsolute: false },
            },
        }),
    ],
    build: {
        rollupOptions: {
            output: {
                manualChunks: (id) => {
                    if (id.includes('chart.js') || id.includes('vue-chartjs')) return 'charts';
                    if (id.includes('node_modules')) return 'vendor';
                },
            },
        },
    },
    resolve: {
        alias: { '@': '/resources/js' },
    },
    test: {
        environment: 'jsdom',
        include: ['resources/js/**/*.test.js'],
    },
});