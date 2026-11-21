<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PageHeader from '../../../Components/Common/PageHeader.vue';
import BaseCard from '../../../Components/Common/BaseCard.vue';
import BaseTable from '../../../Components/Common/BaseTable.vue';
import BaseBadge from '../../../Components/Common/BaseBadge.vue';
import MoneyDisplay from '../../../Components/Common/MoneyDisplay.vue';
import { formatQty, humanize } from '../../../Utils/format';

defineProps({ adjustment: Object });
const columns = [
    { key: 'product', label: 'Product' }, { key: 'batch', label: 'Batch' }, { key: 'quantity', label: 'Quantity', align: 'right' },
    { key: 'unit_cost', label: 'Unit cost', align: 'right' }, { key: 'total_cost', label: 'Total cost', align: 'right' },
];
</script>

<template>
    <Head :title="adjustment.reference_no" />
    <PageHeader :title="adjustment.reference_no"><Link href="/inventory/adjustments" class="btn btn-secondary">All adjustments</Link></PageHeader>
    <BaseCard style="margin-bottom: 16px">
        <div class="row" style="gap: 28px; flex-wrap: wrap">
            <div><div class="muted">Warehouse</div><strong>{{ adjustment.warehouse }}</strong></div>
            <div><div class="muted">Date</div><strong>{{ adjustment.adjusted_at }}</strong></div>
            <div><div class="muted">Change</div><BaseBadge :variant="adjustment.direction === 'in' ? 'success' : 'warning'">{{ adjustment.direction === 'in' ? 'Increase' : 'Decrease' }}</BaseBadge></div>
            <div><div class="muted">Reason</div><strong>{{ humanize(adjustment.reason) }}</strong></div>
            <div><div class="muted">Posted by</div><strong>{{ adjustment.user ?? '—' }}</strong></div>
        </div>
        <p v-if="adjustment.notes" style="margin-bottom: 0">{{ adjustment.notes }}</p>
    </BaseCard>
    <BaseCard flush>
        <BaseTable :columns="columns" :rows="adjustment.items">
            <template #cell-product="{ row }"><strong>{{ row.product }}</strong><span v-if="row.variant" class="muted"> · {{ row.variant }}</span><div class="muted mono">{{ row.sku }}</div></template>
            <template #cell-batch="{ row }">{{ row.batch ?? '—' }}<span v-if="row.expiry_date" class="muted"> · exp {{ row.expiry_date }}</span></template>
            <template #cell-quantity="{ row }">{{ formatQty(row.quantity) }}</template>
            <template #cell-unit_cost="{ row }"><MoneyDisplay :amount="row.unit_cost" /></template>
            <template #cell-total_cost="{ row }"><MoneyDisplay :amount="row.total_cost" /></template>
        </BaseTable>
    </BaseCard>
</template>
