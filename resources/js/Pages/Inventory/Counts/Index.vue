<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '../../../Components/Common/PageHeader.vue';
import BaseCard from '../../../Components/Common/BaseCard.vue';
import BaseTable from '../../../Components/Common/BaseTable.vue';
import BasePagination from '../../../Components/Common/BasePagination.vue';
import BaseBadge from '../../../Components/Common/BaseBadge.vue';
import BaseButton from '../../../Components/Common/BaseButton.vue';
import BaseModal from '../../../Components/Common/BaseModal.vue';
import BaseSelect from '../../../Components/Common/BaseSelect.vue';
import BaseInput from '../../../Components/Common/BaseInput.vue';
import { usePermissions } from '../../../Composables/usePermissions';
import { formatDateTime } from '../../../Utils/format';

defineProps({ counts: Object, warehouses: Array });
const { can } = usePermissions();
const show = ref(false);
const form = useForm({ warehouse_id: '', notes: '' });
const submit = () => form.post('/inventory/counts', { onSuccess: () => { show.value = false; form.reset(); } });

const columns = [
    { key: 'reference_no', label: 'Reference' }, { key: 'warehouse', label: 'Warehouse' }, { key: 'status', label: 'Status' },
    { key: 'items_count', label: 'Lines', align: 'right' }, { key: 'created_at', label: 'Started' }, { key: 'user', label: 'By' },
];
const variant = { draft: 'info', completed: 'success', cancelled: 'neutral' };
</script>

<template>
    <Head title="Stock counts" />
    <PageHeader title="Stock counts">
        <BaseButton v-if="can('inventory.adjust')" @click="show = true">Start a count</BaseButton>
    </PageHeader>
    <BaseCard flush>
        <BaseTable :columns="columns" :rows="counts.data" empty-title="No counts yet" empty-message="A count compares what is on the shelves with what the system says and posts the differences.">
            <template #cell-reference_no="{ row }"><Link :href="`/inventory/counts/${row.id}`"><strong>{{ row.reference_no }}</strong></Link></template>
            <template #cell-status="{ row }"><BaseBadge :variant="variant[row.status]">{{ row.status }}</BaseBadge></template>
            <template #cell-created_at="{ row }">{{ formatDateTime(row.created_at) }}</template>
            <template #cell-user="{ row }">{{ row.user ?? '—' }}</template>
        </BaseTable>
        <BasePagination :paginator="counts" />
    </BaseCard>

    <BaseModal :show="show" title="Start a stock count" @close="show = false">
        <form id="count-form" @submit.prevent="submit">
            <BaseSelect v-model="form.warehouse_id" label="Warehouse" placeholder="Choose…" :options="warehouses.map((w) => ({ value: w.id, label: w.name }))" :error="form.errors.warehouse_id" />
            <BaseInput v-model="form.notes" label="Notes" :error="form.errors.notes" />
            <p class="hint" style="margin: 0">Every product with stock in the warehouse is listed. Enter what you count, then complete the count to post the differences.</p>
        </form>
        <template #footer>
            <BaseButton variant="secondary" @click="show = false">Cancel</BaseButton>
            <BaseButton type="submit" form="count-form" :loading="form.processing">Start count</BaseButton>
        </template>
    </BaseModal>
</template>
