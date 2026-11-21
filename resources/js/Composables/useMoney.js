import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { formatMoney } from '../Utils/format';

/** Formats amounts in the business base currency (or any currency object passed in). */
export function useMoney() {
    const page = usePage();
    const base = computed(() => page.props.tenant?.currency ?? { symbol: '', decimal_places: 2, code: '' });
    const money = (amount, currency = null) => formatMoney(amount, currency ?? base.value);

    return { money, base };
}
