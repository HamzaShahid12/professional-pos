<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '../../Components/Common/PageHeader.vue';
import BaseCard from '../../Components/Common/BaseCard.vue';
import BaseTable from '../../Components/Common/BaseTable.vue';
import BaseBadge from '../../Components/Common/BaseBadge.vue';
import { formatQty } from '../../Utils/format';

const props = defineProps({ batches: Array, days: Number });
const days = ref(props.days);
const apply = () => router.get('/inventory/expiry', { days: days.value }, { preserveState: true, replace: true });

const columns = [
    { key: 'product', label: 'Product' }, { key: 'warehouse', label: 'Warehouse' }, { key: 'batch_number', label: 'Batch' },
    { key: 'expiry_date', label: 'Expires' }, { key: 'quantity', label: 'Quantity', align: 'right' }, { key: 'status', label: 'Status' },
];
</script>

<template>
    <Head title="Expiry report" />
    <PageHeader title="Expiry report"><Link href="/inventory" class="btn btn-secondary">Back to inventory</Link></PageHeader>
    <BaseCard flush>
        <div class="toolbar">
            <label for="days">Expiring within</label>
            <input id="days" v-model.number="days" type="number" min="0" max="365" class="input" style="width: 90px" @change="apply">
            <span class="muted">days (expired batches that still hold stock are always shown)</span>
        </div>
        <BaseTable :columns="columns" :rows="batches" empty-title="Nothing is expiring" empty-message="No batch with stock expires in this period.">
            <template #cell-product="{ row }"><strong>{{ row.product }}</strong><span v-if="row.variant" class="muted"> · {{ row.variant }}</span><div class="muted mono">{{ row.sku }}</div></template>
            <template #cell-batch_number="{ row }">{{ row.batch_number ?? '—' }}</template>
            <template #cell-quantity="{ row }">{{ formatQty(row.quantity) }}</template>
            <template #cell-status="{ row }">
                <BaseBadge :variant="row.status === 'expired' ? 'danger' : 'warning'">{{ row.status === 'expired' ? `Expired ${Math.abs(row.days_left)} d ago` : `${row.days_left} d left` }}</BaseBadge>
            </template>
        </BaseTable>
    </BaseCard>
</template>
