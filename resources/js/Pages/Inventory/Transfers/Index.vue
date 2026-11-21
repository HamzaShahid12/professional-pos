<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PageHeader from '../../../Components/Common/PageHeader.vue';
import BaseCard from '../../../Components/Common/BaseCard.vue';
import BaseTable from '../../../Components/Common/BaseTable.vue';
import BasePagination from '../../../Components/Common/BasePagination.vue';
import { usePermissions } from '../../../Composables/usePermissions';

defineProps({ transfers: Object });
const { can } = usePermissions();
const columns = [
    { key: 'reference_no', label: 'Reference' }, { key: 'transferred_at', label: 'Date' }, { key: 'from', label: 'From' },
    { key: 'to', label: 'To' }, { key: 'items_count', label: 'Lines', align: 'right' }, { key: 'user', label: 'By' },
];
</script>

<template>
    <Head title="Stock transfers" />
    <PageHeader title="Stock transfers">
        <Link v-if="can('inventory.transfer')" href="/inventory/transfers/create" class="btn">New transfer</Link>
    </PageHeader>
    <BaseCard flush>
        <BaseTable :columns="columns" :rows="transfers.data" empty-title="No transfers yet" empty-message="Move stock between warehouses; it leaves one and arrives in the other in a single step.">
            <template #cell-reference_no="{ row }"><Link :href="`/inventory/transfers/${row.id}`"><strong>{{ row.reference_no }}</strong></Link></template>
            <template #cell-user="{ row }">{{ row.user ?? '—' }}</template>
        </BaseTable>
        <BasePagination :paginator="transfers" />
    </BaseCard>
</template>
