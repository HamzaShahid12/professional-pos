<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';
import PageHeader from '../../Components/Common/PageHeader.vue';
import BaseCard from '../../Components/Common/BaseCard.vue';
import BaseBadge from '../../Components/Common/BaseBadge.vue';
import BasePagination from '../../Components/Common/BasePagination.vue';
import MoneyDisplay from '../../Components/Common/MoneyDisplay.vue';
import AccountingTabs from '../../Components/Accounting/AccountingTabs.vue';

const props = defineProps({ account: Object, opening: String, closing: String, lines: Object, filters: Object, branches: Array });
const filter = reactive({ from: props.filters.from ?? '', to: props.filters.to ?? '', branch_id: props.filters.branch_id ?? '' });
watch(filter, () => {
    const params = Object.fromEntries(Object.entries(filter).filter(([, v]) => v !== '' && v !== null));
    router.get(`/accounting/accounts/${props.account.id}/ledger`, params, { preserveState: true, preserveScroll: true, replace: true });
});
const shown = (v) => (Number(v) === 0 ? null : v);
</script>

<template>
    <Head :title="`${account.code} ledger`" />
    <PageHeader :title="`${account.code} · ${account.name}`"><Link href="/accounting" class="btn btn-secondary">All accounts</Link></PageHeader>
    <AccountingTabs />
    <BaseCard flush>
        <div class="toolbar">
            <input v-model="filter.from" type="date" class="input" style="width: 150px" aria-label="From date">
            <input v-model="filter.to" type="date" class="input" style="width: 150px" aria-label="To date">
            <select v-if="branches.length > 1" v-model="filter.branch_id" class="select" style="width: 170px" aria-label="Branch"><option value="">All branches</option><option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option></select>
            <BaseBadge variant="info">{{ account.type }}</BaseBadge>
        </div>
        <table class="table">
            <thead><tr><th>Date</th><th>Entry</th><th>Description</th><th style="text-align: right">Debit</th><th style="text-align: right">Credit</th><th style="text-align: right">Balance</th></tr></thead>
            <tbody>
                <tr><td colspan="5" class="muted">Balance brought forward</td><td style="text-align: right"><MoneyDisplay :amount="opening" /></td></tr>
                <tr v-for="l in lines.data" :key="l.id">
                    <td>{{ l.entry_date }}</td>
                    <td><Link :href="`/accounting/journal/${l.entry_id}`">{{ l.entry_no }}</Link></td>
                    <td>{{ l.memo ? `${l.description} · ${l.memo}` : l.description }}</td>
                    <td style="text-align: right"><MoneyDisplay v-if="shown(l.debit)" :amount="l.debit" /></td>
                    <td style="text-align: right"><MoneyDisplay v-if="shown(l.credit)" :amount="l.credit" /></td>
                    <td style="text-align: right"><MoneyDisplay :amount="l.balance" /></td>
                </tr>
                <tr v-if="!lines.data.length"><td colspan="6" class="muted">No postings in this period.</td></tr>
            </tbody>
            <tfoot><tr><th colspan="5">Closing balance</th><th style="text-align: right"><MoneyDisplay :amount="closing" /></th></tr></tfoot>
        </table>
        <BasePagination :paginator="lines" />
    </BaseCard>
</template>
