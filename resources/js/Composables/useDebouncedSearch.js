import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';

/** Debounced `?search=` filter for Inertia list pages. */
export function useDebouncedSearch(url, initial = '', delay = 300) {
    const search = ref(initial ?? '');
    let timer = null;

    watch(search, (value) => {
        clearTimeout(timer);
        timer = setTimeout(() => {
            router.get(url, value ? { search: value } : {}, { preserveState: true, preserveScroll: true, replace: true });
        }, delay);
    });

    return { search };
}
