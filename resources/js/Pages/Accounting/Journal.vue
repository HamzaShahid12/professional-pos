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
import AccountingTabs from '../../Components/Accounting/AccountingTabs.vue';

const props = defineProps({ entries: Object, filters: Object, accounts: Array, can_manage: Boolean });
const filter = reactive({ search: props.filters.search ?? '', kind: props.filters.kind ?? '', account_id: props.filters.account_id ?? '', from: props.filters.from ?? '', to: props.filters.to ?? '' });
let timer = null;
watch(filter, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        const params = Object.fromEntries(Object.entries(filter).filter(([, v]) => v !== '' && v !== null));
        router.get('/accounting/journal', params, { preserveState: true, preserveScroll: true, replace: true });
    }, 300);
});
const columns = [
    { key: 'entry_no', label: 'Entry' }, { key: 'entry_date', label: 'Date' }, { key: 'description', label: 'Description' },
    { key: 'kind', label: 'Type' }, { key: 'total', label: 'Amount', align: 'right' },
];
const variant = { auto: 'info', manual: 'success', reversal: 'warning' };
</script>

<template>
    <Head title="Journal" />
    <PageHeader title="Journal"><Link v-if="can_manage" href="/accounting/journal/create" class="btn">New journal</Link></PageHeader>
    <AccountingTabs />
    <BaseCard flush>
        <div class="toolbar">
            <SearchInput v-model="filter.search" placeholder="Entry number or description" />
            <select v-model="filter.kind" class="select" style="width: 130px" aria-label="Type"><option value="">Any type</option><option value="auto">Automatic</option><option value="manual">Manual</option><option value="reversal">Reversal</option></select>
            <select v-model="filter.account_id" class="select" style="width: 220px" aria-label="Account"><option value="">All accounts</option><option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} {{ a.name }}</option></select>
            <input v-model="filter.from" type="date" class="input" style="width: 150px" aria-label="From date">
            <input v-model="filter.to" type="date" class="input" style="width: 150px" aria-label="To date">
        </div>
        <BaseTable :columns="columns" :rows="entries.data" empty-title="No journal entries" empty-message="Entries appear as sales, purchases and payments are recorded.">
            <template #cell-entry_no="{ row }"><Link :href="`/accounting/journal/${row.id}`"><strong>{{ row.entry_no }}</strong></Link></template>
            <template #cell-kind="{ row }"><BaseBadge :variant="variant[row.kind]">{{ row.kind }}</BaseBadge></template>
            <template #cell-total="{ row }"><MoneyDisplay :amount="row.total" /></template>
        </BaseTable>
        <BasePagination :paginator="entries" />
    </BaseCard>
</template>
