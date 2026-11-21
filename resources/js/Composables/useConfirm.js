import { reactive } from 'vue';

const state = reactive({ open: false, title: '', message: '', confirmText: 'Confirm', danger: false, resolve: null });

/** Promise-based confirmation: `if (await confirm({ title, message, danger: true })) { ... }` */
export function useConfirm() {
    const confirm = (options = {}) =>
        new Promise((resolve) => {
            Object.assign(state, {
                open: true,
                title: options.title ?? 'Are you sure?',
                message: options.message ?? '',
                confirmText: options.confirmText ?? 'Confirm',
                danger: options.danger ?? false,
                resolve,
            });
        });

    const answer = (value) => {
        state.open = false;
        state.resolve?.(value);
        state.resolve = null;
    };

    return { confirm, state, answer };
}
