<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PageHeader from '../../../Components/Common/PageHeader.vue';
import BaseCard from '../../../Components/Common/BaseCard.vue';
import BaseTable from '../../../Components/Common/BaseTable.vue';
import MoneyDisplay from '../../../Components/Common/MoneyDisplay.vue';
import { formatQty } from '../../../Utils/format';

defineProps({ transfer: Object });
const columns = [
    { key: 'product', label: 'Product' }, { key: 'quantity', label: 'Quantity', align: 'right' },
    { key: 'unit_cost', label: 'Unit cost', align: 'right' }, { key: 'total_cost', label: 'Total cost', align: 'right' },
];
</script>

<template>
    <Head :title="transfer.reference_no" />
    <PageHeader :title="transfer.reference_no"><Link href="/inventory/transfers" class="btn btn-secondary">All transfers</Link></PageHeader>
    <BaseCard style="margin-bottom: 16px">
        <div class="row" style="gap: 28px; flex-wrap: wrap">
            <div><div class="muted">From</div><strong>{{ transfer.from }}</strong></div>
            <div><div class="muted">To</div><strong>{{ transfer.to }}</strong></div>
            <div><div class="muted">Date</div><strong>{{ transfer.transferred_at }}</strong></div>
            <div><div class="muted">By</div><strong>{{ transfer.user ?? '—' }}</strong></div>
        </div>
        <p v-if="transfer.notes" style="margin-bottom: 0">{{ transfer.notes }}</p>
    </BaseCard>
    <BaseCard flush>
        <BaseTable :columns="columns" :rows="transfer.items">
            <template #cell-product="{ row }"><strong>{{ row.product }}</strong><span v-if="row.variant" class="muted"> · {{ row.variant }}</span><div class="muted mono">{{ row.sku }}</div></template>
            <template #cell-quantity="{ row }">{{ formatQty(row.quantity) }}</template>
            <template #cell-unit_cost="{ row }"><MoneyDisplay :amount="row.unit_cost" /></template>
            <template #cell-total_cost="{ row }"><MoneyDisplay :amount="row.total_cost" /></template>
        </BaseTable>
    </BaseCard>
</template>
