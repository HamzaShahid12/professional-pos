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
import { usePermissions } from '../../Composables/usePermissions';

const props = defineProps({ purchases: Object, filters: Object, suppliers: Array });
const { can } = usePermissions();

const filter = reactive({ search: props.filters.search ?? '', status: props.filters.status ?? '', supplier_id: props.filters.supplier_id ?? '', from: props.filters.from ?? '', to: props.filters.to ?? '' });
let timer = null;
watch(filter, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        const params = Object.fromEntries(Object.entries(filter).filter(([, v]) => v !== '' && v !== null));
        router.get('/purchases', params, { preserveState: true, preserveScroll: true, replace: true });
    }, 300);
});

const columns = [
    { key: 'reference_no', label: 'Reference' }, { key: 'order_date', label: 'Date' }, { key: 'supplier', label: 'Supplier' },
    { key: 'warehouse', label: 'Warehouse' }, { key: 'status', label: 'Status' }, { key: 'total', label: 'Total', align: 'right' },
];
const variant = { ordered: 'info', received: 'success', cancelled: 'neutral' };
</script>

<template>
    <Head title="Purchases" />
    <PageHeader title="Purchases"><Link v-if="can('purchases.create')" href="/purchases/create" class="btn">New purchase</Link></PageHeader>
    <BaseCard flush>
        <div class="toolbar">
            <SearchInput v-model="filter.search" placeholder="Reference or supplier invoice no." />
            <select v-model="filter.status" class="select" style="width: 140px" aria-label="Status"><option value="">Any status</option><option value="ordered">Ordered</option><option value="received">Received</option><option value="cancelled">Cancelled</option></select>
            <select v-model="filter.supplier_id" class="select" style="width: 180px" aria-label="Supplier"><option value="">All suppliers</option><option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option></select>
            <input v-model="filter.from" type="date" class="input" style="width: 150px" aria-label="From date">
            <input v-model="filter.to" type="date" class="input" style="width: 150px" aria-label="To date">
        </div>
        <BaseTable :columns="columns" :rows="purchases.data" empty-title="No purchases" empty-message="Create a purchase order, or receive goods directly.">
            <template #cell-reference_no="{ row }"><Link :href="`/purchases/${row.id}`"><strong>{{ row.reference_no }}</strong></Link></template>
            <template #cell-status="{ row }"><BaseBadge :variant="variant[row.status]">{{ row.status }}</BaseBadge></template>
            <template #cell-total="{ row }"><MoneyDisplay :amount="row.total" :currency="row.currency" /></template>
        </BaseTable>
        <BasePagination :paginator="purchases" />
    </BaseCard>
</template>
