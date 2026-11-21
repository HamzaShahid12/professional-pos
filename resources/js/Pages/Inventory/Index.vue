<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';
import PageHeader from '../../Components/Common/PageHeader.vue';
import BaseCard from '../../Components/Common/BaseCard.vue';
import BaseTable from '../../Components/Common/BaseTable.vue';
import BasePagination from '../../Components/Common/BasePagination.vue';
import BaseBadge from '../../Components/Common/BaseBadge.vue';
import SearchInput from '../../Components/Common/SearchInput.vue';
import MoneyDisplay from '../../Components/Common/MoneyDisplay.vue';
import AppIcon from '../../Components/Common/AppIcon.vue';
import { usePermissions } from '../../Composables/usePermissions';
import { formatQty } from '../../Utils/format';

const props = defineProps({ stock: Object, valuation: Object, counts: Object, filters: Object, warehouses: Array, categories: Array });
const { can } = usePermissions();

const filter = reactive({
    search: props.filters.search ?? '', warehouse_id: props.filters.warehouse_id ?? '',
    category_id: props.filters.category_id ?? '', status: props.filters.status ?? '',
});
let timer = null;
watch(filter, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        const params = Object.fromEntries(Object.entries(filter).filter(([, v]) => v !== '' && v !== null));
        router.get('/inventory', params, { preserveState: true, preserveScroll: true, replace: true });
    }, 300);
});

const columns = [
    { key: 'name', label: 'Product' }, { key: 'quantity', label: 'On hand', align: 'right' }, { key: 'min_stock', label: 'Minimum', align: 'right' },
    { key: 'total_value', label: 'Stock value', align: 'right' }, { key: 'status', label: 'Status' },
];
const statusVariant = { in: 'success', low: 'warning', out: 'danger' };
const statusLabel = { in: 'In stock', low: 'Low stock', out: 'Out of stock' };
const setStatus = (s) => { filter.status = filter.status === s ? '' : s; };
</script>

<template>
    <Head title="Inventory" />
    <PageHeader title="Inventory">
        <Link href="/inventory/movements" class="btn btn-secondary">Stock ledger</Link>
        <Link v-if="can('inventory.view')" href="/inventory/expiry" class="btn btn-secondary">Expiry</Link>
        <Link v-if="can('inventory.transfer')" href="/inventory/transfers/create" class="btn btn-secondary">Transfer</Link>
        <Link v-if="can('inventory.adjust')" href="/inventory/adjustments/create" class="btn">Adjust stock</Link>
    </PageHeader>

    <div class="grid grid-3" style="margin-bottom: 16px">
        <div class="card stat"><span class="stat-icon"><AppIcon name="currency" :size="22" /></span>
            <div><div class="stat-value"><MoneyDisplay :amount="valuation.value" /></div><div class="stat-label">Stock value (at cost)</div></div></div>
        <button type="button" class="card stat" style="text-align: left; cursor: pointer" @click="setStatus('low')"><span class="stat-icon"><AppIcon name="activity" :size="22" /></span>
            <div><div class="stat-value">{{ counts.low }}</div><div class="stat-label">Low stock products</div></div></button>
        <button type="button" class="card stat" style="text-align: left; cursor: pointer" @click="setStatus('out')"><span class="stat-icon"><AppIcon name="x" :size="22" /></span>
            <div><div class="stat-value">{{ counts.out }}</div><div class="stat-label">Out of stock products</div></div></button>
    </div>

    <BaseCard flush>
        <div class="toolbar">
            <SearchInput v-model="filter.search" placeholder="Name, SKU or barcode" />
            <select v-model="filter.warehouse_id" class="select" style="width: 180px" aria-label="Warehouse"><option value="">All warehouses</option><option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option></select>
            <select v-model="filter.category_id" class="select" style="width: 170px" aria-label="Category"><option value="">All categories</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select>
            <select v-model="filter.status" class="select" style="width: 150px" aria-label="Status"><option value="">Any status</option><option value="in">In stock</option><option value="low">Low stock</option><option value="out">Out of stock</option></select>
        </div>
        <BaseTable :columns="columns" :rows="stock.data" empty-title="No stock found" empty-message="Receive stock with an adjustment (opening stock) or a purchase. Products appear here once they have a stock record.">
            <template #cell-name="{ row }">
                <Link :href="`/inventory/movements?product_id=${row.product_id}`"><strong>{{ row.name }}</strong></Link><span v-if="row.variant" class="muted"> · {{ row.variant }}</span>
                <div class="muted mono">{{ row.sku }}</div>
            </template>
            <template #cell-quantity="{ row }">{{ formatQty(row.quantity) }} <span class="muted">{{ row.unit }}</span></template>
            <template #cell-min_stock="{ row }">{{ Number(row.min_stock) > 0 ? formatQty(row.min_stock) : '—' }}</template>
            <template #cell-total_value="{ row }"><MoneyDisplay :amount="row.total_value" /></template>
            <template #cell-status="{ row }"><BaseBadge :variant="statusVariant[row.status]">{{ statusLabel[row.status] }}</BaseBadge></template>
        </BaseTable>
        <BasePagination :paginator="stock" />
    </BaseCard>
</template>
