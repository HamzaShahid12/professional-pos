<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';
import PageHeader from '../../Components/Common/PageHeader.vue';
import BaseCard from '../../Components/Common/BaseCard.vue';
import BaseBadge from '../../Components/Common/BaseBadge.vue';
import MoneyDisplay from '../../Components/Common/MoneyDisplay.vue';
import AccountingTabs from '../../Components/Accounting/AccountingTabs.vue';

const props = defineProps({ report: Object, filters: Object });
const filter = reactive({ as_of: props.filters.as_of ?? '' });
watch(filter, () => router.get('/accounting/balance-sheet', filter.as_of ? { as_of: filter.as_of } : {}, { preserveState: true, replace: true }));
</script>

<template>
    <Head title="Balance sheet" />
    <PageHeader title="Balance sheet" />
    <AccountingTabs />
    <BaseCard flush>
        <div class="toolbar">
            <label class="row">As of <input v-model="filter.as_of" type="date" class="input" style="width: 150px"></label>
            <BaseBadge :variant="report.balanced ? 'success' : 'danger'">{{ report.balanced ? 'Assets = liabilities + equity' : 'Out of balance' }}</BaseBadge>
        </div>
        <table class="table">
            <tbody>
                <tr><th colspan="2">Assets</th></tr>
                <tr v-for="r in report.assets" :key="r.id"><td><Link :href="`/accounting/accounts/${r.id}/ledger`">{{ r.code }} · {{ r.name }}</Link></td><td style="text-align: right"><MoneyDisplay :amount="r.amount" /></td></tr>
                <tr><td><strong>Total assets</strong></td><td style="text-align: right"><strong><MoneyDisplay :amount="report.total_assets" /></strong></td></tr>
                <tr><th colspan="2">Liabilities</th></tr>
                <tr v-for="r in report.liabilities" :key="r.id"><td><Link :href="`/accounting/accounts/${r.id}/ledger`">{{ r.code }} · {{ r.name }}</Link></td><td style="text-align: right"><MoneyDisplay :amount="r.amount" /></td></tr>
                <tr><td><strong>Total liabilities</strong></td><td style="text-align: right"><strong><MoneyDisplay :amount="report.total_liabilities" /></strong></td></tr>
                <tr><th colspan="2">Equity</th></tr>
                <tr v-for="r in report.equity" :key="r.id"><td><Link :href="`/accounting/accounts/${r.id}/ledger`">{{ r.code }} · {{ r.name }}</Link></td><td style="text-align: right"><MoneyDisplay :amount="r.amount" /></td></tr>
                <tr><td>Profit to date</td><td style="text-align: right"><MoneyDisplay :amount="report.profit_to_date" /></td></tr>
                <tr><td><strong>Total equity</strong></td><td style="text-align: right"><strong><MoneyDisplay :amount="report.total_equity" /></strong></td></tr>
            </tbody>
        </table>
    </BaseCard>
</template>
