import '../css/app.css';
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { createPinia } from 'pinia';
import AppLayout from './Layouts/AppLayout.vue';

const appName = import.meta.env.VITE_APP_NAME || 'Professional POS';

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    // Each page is its own small file, loaded when first visited (the charts library is only fetched for pages that draw charts).
    resolve: async (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue');
        const load = pages[`./Pages/${name}.vue`];
        if (!load) throw new Error(`Unknown page: ${name}`);
        const page = await load();
        // Auth pages bring their own layout; everything else uses the application shell.
        page.default.layout = page.default.layout === undefined ? AppLayout : page.default.layout;
        return page;
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(createPinia())
            .mount(el);
    },
    progress: { color: '#5b4bdb' },
});