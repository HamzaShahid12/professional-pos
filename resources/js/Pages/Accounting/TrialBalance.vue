<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';
import PageHeader from '../../Components/Common/PageHeader.vue';
import BaseCard from '../../Components/Common/BaseCard.vue';
import BaseBadge from '../../Components/Common/BaseBadge.vue';
import MoneyDisplay from '../../Components/Common/MoneyDisplay.vue';
import AccountingTabs from '../../Components/Accounting/AccountingTabs.vue';

const props = defineProps({ report: Object, reconciliation: Array, filters: Object, branches: Array });
const filter = reactive({ as_of: props.filters.as_of ?? '', branch_id: props.filters.branch_id ?? '' });
watch(filter, () => {
    const params = Object.fromEntries(Object.entries(filter).filter(([, v]) => v !== '' && v !== null));
    router.get('/accounting/trial-balance', params, { preserveState: true, preserveScroll: true, replace: true });
});
</script>

<template>
    <Head title="Trial balance" />
    <PageHeader title="Trial balance" />
    <AccountingTabs />
    <BaseCard v-if="reconciliation" title="Do the books agree with your records?" style="margin-bottom: 16px">
        <table class="table">
            <thead><tr><th>Check</th><th style="text-align: right">Books</th><th style="text-align: right">Records</th><th style="text-align: right">Difference</th><th /></tr></thead>
            <tbody>
                <tr v-for="r in reconciliation" :key="r.label">
                    <td>{{ r.label }}</td>
                    <td style="text-align: right"><MoneyDisplay :amount="r.books" /></td>
                    <td style="text-align: right"><MoneyDisplay :amount="r.records" /></td>
                    <td style="text-align: right"><MoneyDisplay :amount="r.difference" /></td>
                    <td><BaseBadge :variant="r.ok ? 'success' : 'danger'">{{ r.ok ? 'Agrees' : 'Differs' }}</BaseBadge></td>
                </tr>
            </tbody>
        </table>
    </BaseCard>
    <BaseCard flush>
        <div class="toolbar">
            <label class="row">As of <input v-model="filter.as_of" type="date" class="input" style="width: 150px"></label>
            <select v-if="branches.length > 1" v-model="filter.branch_id" class="select" style="width: 170px" aria-label="Branch"><option value="">All branches</option><option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option></select>
            <BaseBadge :variant="report.balanced ? 'success' : 'danger'">{{ report.balanced ? 'Balanced' : 'Out of balance' }}</BaseBadge>
        </div>
        <table class="table">
            <thead><tr><th>Code</th><th>Account</th><th style="text-align: right">Debit</th><th style="text-align: right">Credit</th></tr></thead>
            <tbody>
                <tr v-if="!report.rows.length"><td colspan="4" class="muted">No postings yet.</td></tr>
                <tr v-for="r in report.rows" :key="r.id">
                    <td class="mono">{{ r.code }}</td>
                    <td><Link :href="`/accounting/accounts/${r.id}/ledger`">{{ r.name }}</Link></td>
                    <td style="text-align: right"><MoneyDisplay v-if="Number(r.debit)" :amount="r.debit" /></td>
                    <td style="text-align: right"><MoneyDisplay v-if="Number(r.credit)" :amount="r.credit" /></td>
                </tr>
            </tbody>
            <tfoot><tr><th colspan="2">Total</th><th style="text-align: right"><MoneyDisplay :amount="report.total_debit" /></th><th style="text-align: right"><MoneyDisplay :amount="report.total_credit" /></th></tr></tfoot>
        </table>
    </BaseCard>
</template>
