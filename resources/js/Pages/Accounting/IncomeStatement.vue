<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';
import PageHeader from '../../Components/Common/PageHeader.vue';
import BaseCard from '../../Components/Common/BaseCard.vue';
import MoneyDisplay from '../../Components/Common/MoneyDisplay.vue';
import AccountingTabs from '../../Components/Accounting/AccountingTabs.vue';

const props = defineProps({ report: Object, filters: Object, branches: Array });
const filter = reactive({ from: props.filters.from ?? '', to: props.filters.to ?? '', branch_id: props.filters.branch_id ?? '' });
watch(filter, () => {
    const params = Object.fromEntries(Object.entries(filter).filter(([, v]) => v !== '' && v !== null));
    router.get('/accounting/income-statement', params, { preserveState: true, preserveScroll: true, replace: true });
});
const sections = [['Revenue', 'revenue', 'total_revenue'], ['Cost of sales', 'cost_of_sales', 'total_cost_of_sales']];
</script>

<template>
    <Head title="Profit & loss" />
    <PageHeader title="Profit & loss" />
    <AccountingTabs />
    <BaseCard flush>
        <div class="toolbar">
            <input v-model="filter.from" type="date" class="input" style="width: 150px" aria-label="From date">
            <input v-model="filter.to" type="date" class="input" style="width: 150px" aria-label="To date">
            <select v-if="branches.length > 1" v-model="filter.branch_id" class="select" style="width: 170px" aria-label="Branch"><option value="">All branches</option><option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option></select>
        </div>
        <table class="table">
            <tbody>
                <template v-for="[title, key, total] in sections" :key="key">
                    <tr><th colspan="2">{{ title }}</th></tr>
                    <tr v-for="r in report[key]" :key="r.id"><td><Link :href="`/accounting/accounts/${r.id}/ledger`">{{ r.code }} · {{ r.name }}</Link></td><td style="text-align: right"><MoneyDisplay :amount="r.amount" /></td></tr>
                    <tr><td><strong>Total {{ title.toLowerCase() }}</strong></td><td style="text-align: right"><strong><MoneyDisplay :amount="report[total]" /></strong></td></tr>
                </template>
                <tr><th>Gross profit</th><th style="text-align: right"><MoneyDisplay :amount="report.gross_profit" /></th></tr>
                <tr><th colspan="2">Operating expenses</th></tr>
                <tr v-for="r in report.expenses" :key="r.id"><td><Link :href="`/accounting/accounts/${r.id}/ledger`">{{ r.code }} · {{ r.name }}</Link></td><td style="text-align: right"><MoneyDisplay :amount="r.amount" /></td></tr>
                <tr><td><strong>Total operating expenses</strong></td><td style="text-align: right"><strong><MoneyDisplay :amount="report.total_expenses" /></strong></td></tr>
                <tr><th>Net profit</th><th style="text-align: right"><MoneyDisplay :amount="report.net_profit" /></th></tr>
            </tbody>
        </table>
    </BaseCard>
</template>
