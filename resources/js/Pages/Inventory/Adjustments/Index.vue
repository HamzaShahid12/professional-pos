<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PageHeader from '../../../Components/Common/PageHeader.vue';
import BaseCard from '../../../Components/Common/BaseCard.vue';
import BaseTable from '../../../Components/Common/BaseTable.vue';
import BasePagination from '../../../Components/Common/BasePagination.vue';
import BaseBadge from '../../../Components/Common/BaseBadge.vue';
import { usePermissions } from '../../../Composables/usePermissions';
import { humanize } from '../../../Utils/format';

defineProps({ adjustments: Object });
const { can } = usePermissions();
const columns = [
    { key: 'reference_no', label: 'Reference' }, { key: 'adjusted_at', label: 'Date' }, { key: 'warehouse', label: 'Warehouse' },
    { key: 'direction', label: 'Direction' }, { key: 'reason', label: 'Reason' }, { key: 'items_count', label: 'Lines', align: 'right' }, { key: 'user', label: 'By' },
];
</script>

<template>
    <Head title="Stock adjustments" />
    <PageHeader title="Stock adjustments">
        <Link v-if="can('inventory.adjust')" href="/inventory/adjustments/create" class="btn">New adjustment</Link>
    </PageHeader>
    <BaseCard flush>
        <BaseTable :columns="columns" :rows="adjustments.data" empty-title="No adjustments yet" empty-message="Use an adjustment to enter opening stock or correct quantities.">
            <template #cell-reference_no="{ row }"><Link :href="`/inventory/adjustments/${row.id}`"><strong>{{ row.reference_no }}</strong></Link></template>
            <template #cell-direction="{ row }"><BaseBadge :variant="row.direction === 'in' ? 'success' : 'warning'">{{ row.direction === 'in' ? 'Increase' : 'Decrease' }}</BaseBadge></template>
            <template #cell-reason="{ row }">{{ humanize(row.reason) }}</template>
            <template #cell-user="{ row }">{{ row.user ?? '—' }}</template>
        </BaseTable>
        <BasePagination :paginator="adjustments" />
    </BaseCard>
</template>
