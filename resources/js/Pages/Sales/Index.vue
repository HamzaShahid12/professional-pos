<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import PageHeader from '../../Components/Common/PageHeader.vue';
import BaseCard from '../../Components/Common/BaseCard.vue';
import BaseTable from '../../Components/Common/BaseTable.vue';
import BasePagination from '../../Components/Common/BasePagination.vue';
import BaseBadge from '../../Components/Common/BaseBadge.vue';
import SearchInput from '../../Components/Common/SearchInput.vue';
import MoneyDisplay from '../../Components/Common/MoneyDisplay.vue';
import { usePermissions } from '../../Composables/usePermissions';
import { formatDateTime } from '../../Utils/format';

const props = defineProps({ sales: Object, filters: Object, customers: Array });
const { can } = usePermissions();
const tenant = computed(() => usePage().props.tenant);

const filter = reactive({ search: props.filters.search ?? '', status: props.filters.status ?? '', customer_id: props.filters.customer_id ?? '', payment_status: props.filters.payment_status ?? '', from: props.filters.from ?? '', to: props.filters.to ?? '' });
let timer = null;
watch(filter, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        const params = Object.fromEntries(Object.entries(filter).filter(([, v]) => v !== '' && v !== null));
        router.get('/sales', params, { preserveState: true, preserveScroll: true, replace: true });
    }, 300);
});

const columns = [
    { key: 'reference_no', label: 'Invoice' }, { key: 'sold_at', label: 'Date' }, { key: 'customer', label: 'Customer' }, { key: 'cashier', label: 'Cashier' },
    { key: 'status', label: 'Status' }, { key: 'total', label: 'Total', align: 'right' }, { key: 'balance_due', label: 'Due', align: 'right' },
];
const variant = { paid: 'success', partial: 'warning', unpaid: 'danger' };
</script>

<template>
    <Head title="Sales" />
    <PageHeader title="Sales"><Link v-if="can('sales.create')" href="/pos" class="btn">Open POS</Link></PageHeader>
    <BaseCard flush>
        <div class="toolbar">
            <SearchInput v-model="filter.search" placeholder="Invoice number" />
            <select v-model="filter.status" class="select" style="width: 130px" aria-label="Status"><option value="">Any status</option><option value="completed">Completed</option><option value="voided">Voided</option></select>
            <select v-model="filter.payment_status" class="select" style="width: 130px" aria-label="Payment"><option value="">Any payment</option><option value="paid">Paid</option><option value="partial">Partial</option><option value="unpaid">Unpaid</option></select>
            <select v-if="customers.length" v-model="filter.customer_id" class="select" style="width: 170px" aria-label="Customer"><option value="">All customers</option><option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option></select>
            <input v-model="filter.from" type="date" class="input" style="width: 150px" aria-label="From date">
            <input v-model="filter.to" type="date" class="input" style="width: 150px" aria-label="To date">
        </div>
        <BaseTable :columns="columns" :rows="sales.data" empty-title="No sales" empty-message="Completed sales appear here.">
            <template #cell-reference_no="{ row }"><Link :href="`/sales/${row.id}`"><strong>{{ row.reference_no }}</strong></Link></template>
            <template #cell-sold_at="{ row }">{{ formatDateTime(row.sold_at, tenant?.business.timezone) }}</template>
            <template #cell-customer="{ row }">{{ row.customer ?? 'Walk-in' }}</template>
            <template #cell-status="{ row }">
                <BaseBadge v-if="row.status === 'voided'" variant="neutral">Voided</BaseBadge>
                <BaseBadge v-else :variant="variant[row.payment_status]">{{ row.payment_status }}</BaseBadge>
            </template>
            <template #cell-total="{ row }"><MoneyDisplay :amount="row.total" /></template>
            <template #cell-balance_due="{ row }"><MoneyDisplay :amount="row.balance_due" /></template>
        </BaseTable>
        <BasePagination :paginator="sales" />
    </BaseCard>
</template>
