<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import PageHeader from '../../../Components/Common/PageHeader.vue';
import BaseCard from '../../../Components/Common/BaseCard.vue';
import BaseTable from '../../../Components/Common/BaseTable.vue';
import BaseBadge from '../../../Components/Common/BaseBadge.vue';
import BaseButton from '../../../Components/Common/BaseButton.vue';
import { usePermissions } from '../../../Composables/usePermissions';
import { useConfirm } from '../../../Composables/useConfirm';
import { formatQty } from '../../../Utils/format';

const props = defineProps({ count: Object });
const { can } = usePermissions();
const { confirm } = useConfirm();

const draft = props.count.status === 'draft';
const form = useForm({ items: props.count.items.map((i) => ({ id: i.id, counted_qty: i.counted_qty ?? '' })) });
const byId = (id) => form.items.find((i) => i.id === id);
const err = (id) => form.errors[`items.${form.items.findIndex((i) => i.id === id)}.counted_qty`];

const save = () => form.transform((d) => ({ items: d.items.map((i) => ({ id: i.id, counted_qty: i.counted_qty === '' ? null : i.counted_qty })) })).put(`/inventory/counts/${props.count.id}`, { preserveScroll: true });

async function complete() {
    if (await confirm({ title: 'Complete count', message: 'Differences will be posted to stock as adjustments and the count can no longer be changed. Lines left blank are not touched.', confirmText: 'Post differences' })) {
        form.transform((d) => ({ items: d.items.map((i) => ({ id: i.id, counted_qty: i.counted_qty === '' ? null : i.counted_qty })) }))
            .put(`/inventory/counts/${props.count.id}`, { preserveScroll: true, onSuccess: () => router.post(`/inventory/counts/${props.count.id}/complete`) });
    }
}
async function cancel() {
    if (await confirm({ title: 'Cancel count', message: 'The count is discarded. No stock changes are made.', confirmText: 'Cancel count', danger: true })) {
        router.post(`/inventory/counts/${props.count.id}/cancel`);
    }
}

const columns = draft
    ? [{ key: 'product', label: 'Product' }, { key: 'system_qty', label: 'System (at start)', align: 'right' }, { key: 'counted', label: 'Counted', align: 'right' }]
    : [{ key: 'product', label: 'Product' }, { key: 'expected_qty', label: 'System', align: 'right' }, { key: 'counted_qty', label: 'Counted', align: 'right' }, { key: 'difference', label: 'Difference', align: 'right' }];
const variant = { draft: 'info', completed: 'success', cancelled: 'neutral' };
</script>

<template>
    <Head :title="count.reference_no" />
    <PageHeader :title="count.reference_no">
        <Link href="/inventory/counts" class="btn btn-secondary">All counts</Link>
        <template v-if="draft && can('inventory.adjust')">
            <BaseButton variant="ghost" @click="cancel">Cancel count</BaseButton>
            <BaseButton variant="secondary" :loading="form.processing" @click="save">Save counts</BaseButton>
            <BaseButton :loading="form.processing" @click="complete">Complete count</BaseButton>
        </template>
    </PageHeader>
    <BaseCard style="margin-bottom: 16px">
        <div class="row" style="gap: 28px; flex-wrap: wrap">
            <div><div class="muted">Warehouse</div><strong>{{ count.warehouse }}</strong></div>
            <div><div class="muted">Status</div><BaseBadge :variant="variant[count.status]">{{ count.status }}</BaseBadge></div>
            <div><div class="muted">Started by</div><strong>{{ count.user ?? '—' }}</strong></div>
        </div>
        <p v-if="count.notes" style="margin-bottom: 0">{{ count.notes }}</p>
    </BaseCard>
    <BaseCard flush>
        <BaseTable :columns="columns" :rows="count.items">
            <template #cell-product="{ row }"><strong>{{ row.product }}</strong><span v-if="row.variant" class="muted"> · {{ row.variant }}</span><div class="muted mono">{{ row.sku }}</div></template>
            <template #cell-system_qty="{ row }">{{ formatQty(row.system_qty) }} <span class="muted">{{ row.unit }}</span></template>
            <template #cell-counted="{ row }">
                <input v-if="can('inventory.adjust')" v-model="byId(row.id).counted_qty" class="input" type="number" min="0" :step="row.allow_decimal ? '0.0001' : '1'" style="width: 120px; text-align: right" :aria-label="`Counted quantity of ${row.product}`">
                <span v-if="err(row.id)" class="error-text" style="display: block">{{ err(row.id) }}</span>
            </template>
            <template #cell-expected_qty="{ row }">{{ row.expected_qty === null ? '—' : formatQty(row.expected_qty) }}</template>
            <template #cell-counted_qty="{ row }">{{ row.counted_qty === null ? 'Not counted' : formatQty(row.counted_qty) }}</template>
            <template #cell-difference="{ row }">
                <template v-if="row.difference === null">—</template>
                <strong v-else :class="Number(row.difference) < 0 ? 'error-text' : ''">{{ Number(row.difference) > 0 ? '+' : '' }}{{ formatQty(row.difference) }}</strong>
            </template>
        </BaseTable>
    </BaseCard>
</template>
