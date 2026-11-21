import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Frontend permission helper. This only controls what is SHOWN; every route and action
 * is enforced again on the server by middleware, policies and Form Requests.
 */
export function usePermissions() {
    const page = usePage();
    const permissions = computed(() => page.props.auth?.user?.permissions ?? []);
    const roles = computed(() => page.props.auth?.user?.roles ?? []);

    const can = (permission) => !permission || permissions.value.includes(permission);
    const canAny = (list = []) => list.length === 0 || list.some(can);
    const hasRole = (role) => roles.value.includes(role);

    return { can, canAny, hasRole, permissions, roles };
}
