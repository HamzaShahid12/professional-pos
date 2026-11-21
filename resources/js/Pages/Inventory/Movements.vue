<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';
import PageHeader from '../../Components/Common/PageHeader.vue';
import BaseCard from '../../Components/Common/BaseCard.vue';
import BaseTable from '../../Components/Common/BaseTable.vue';
import BasePagination from '../../Components/Common/BasePagination.vue';
import BaseBadge from '../../Components/Common/BaseBadge.vue';
import MoneyDisplay from '../../Components/Common/MoneyDisplay.vue';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { formatDateTime, formatQty, humanize } from '../../Utils/format';

const props = defineProps({ movements: Object, filters: Object, types: Array, warehouses: Array, product: Object });
const tenant = computed(() => usePage().props.tenant);

const filter = reactive({
    warehouse_id: props.filters.warehouse_id ?? '', type: props.filters.type ?? '', from: props.filters.from ?? '', to: props.filters.to ?? '',
    product_id: props.filters.product_id ?? '',
});
let timer = null;
watch(filter, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        const params = Object.fromEntries(Object.entries(filter).filter(([, v]) => v !== '' && v !== null));
        router.get('/inventory/movements', params, { preserveState: true, preserveScroll: true, replace: true });
    }, 300);
});

const columns = [
    { key: 'at', label: 'Date' }, { key: 'product', label: 'Product' }, { key: 'warehouse', label: 'Warehouse' }, { key: 'type', label: 'Type' },
    { key: 'quantity', label: 'Qty', align: 'right' }, { key: 'unit_cost', label: 'Unit cost', align: 'right' },
    { key: 'total_cost', label: 'Value', align: 'right' }, { key: 'balance_after', label: 'Balance', align: 'right' }, { key: 'notes', label: 'Note' },
];
const refLink = (m) => ({ stock_adjustment: `/inventory/adjustments/${m.reference_id}`, stock_transfer: `/inventory/transfers/${m.reference_id}`, stock_count: `/inventory/counts/${m.reference_id}` })[m.reference_type];
</script>

<template>
    <Head title="Stock ledger" />
    <PageHeader title="Stock ledger"><Link href="/inventory" class="btn btn-secondary">Back to inventory</Link></PageHeader>
    <p class="muted" style="margin-top: 0">Every stock change is recorded here and can never be edited or deleted. Mistakes are fixed with a new, opposite entry.</p>

    <BaseCard flush>
        <div class="toolbar">
            <span v-if="product" class="row">Product: <strong>{{ product.name }}</strong> <button class="btn btn-ghost btn-sm" type="button" @click="filter.product_id = ''">Clear</button></span>
            <select v-model="filter.warehouse_id" class="select" style="width: 180px" aria-label="Warehouse"><option value="">All warehouses</option><option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option></select>
            <select v-model="filter.type" class="select" style="width: 180px" aria-label="Type"><option value="">All types</option><option v-for="t in types" :key="t" :value="t">{{ humanize(t) }}</option></select>
            <input v-model="filter.from" type="date" class="input" style="width: 150px" aria-label="From date">
            <input v-model="filter.to" type="date" class="input" style="width: 150px" aria-label="To date">
        </div>
        <BaseTable :columns="columns" :rows="movements.data" empty-title="No movements" empty-message="Nothing matches these filters.">
            <template #cell-at="{ row }">{{ formatDateTime(row.at, tenant?.business.timezone) }}</template>
            <template #cell-product="{ row }"><strong>{{ row.product }}</strong><span v-if="row.variant" class="muted"> · {{ row.variant }}</span><div class="muted mono">{{ row.sku }}</div></template>
            <template #cell-type="{ row }"><BaseBadge :variant="Number(row.quantity) >= 0 ? 'success' : 'warning'">{{ humanize(row.type) }}</BaseBadge></template>
            <template #cell-quantity="{ row }"><strong>{{ Number(row.quantity) > 0 ? '+' : '' }}{{ formatQty(row.quantity) }}</strong></template>
            <template #cell-unit_cost="{ row }"><MoneyDisplay :amount="row.unit_cost" /></template>
            <template #cell-total_cost="{ row }"><MoneyDisplay :amount="row.total_cost" /></template>
            <template #cell-balance_after="{ row }">{{ formatQty(row.balance_after) }}</template>
            <template #cell-notes="{ row }"><Link v-if="refLink(row)" :href="refLink(row)">{{ row.notes ?? 'View document' }}</Link><template v-else>{{ row.notes ?? '—' }}</template></template>
        </BaseTable>
        <BasePagination :paginator="movements" />
    </BaseCard>
</template>
