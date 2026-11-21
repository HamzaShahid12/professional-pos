<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import PageHeader from '../../../Components/Common/PageHeader.vue';
import BaseCard from '../../../Components/Common/BaseCard.vue';
import BaseButton from '../../../Components/Common/BaseButton.vue';
import BaseInput from '../../../Components/Common/BaseInput.vue';
import BaseSelect from '../../../Components/Common/BaseSelect.vue';
import StockLinesEditor from '../../../Components/Inventory/StockLinesEditor.vue';

const props = defineProps({ warehouses: Array, reasons: Object });

const today = new Date();
const localToday = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;

const lines = ref([]);
const form = useForm({
    warehouse_id: props.warehouses.length === 1 ? props.warehouses[0].id : '',
    direction: 'in', reason: 'opening_stock', adjusted_at: localToday, notes: '', items: [],
});

const reasonLabels = { opening_stock: 'Opening stock', found: 'Stock found', correction: 'Correction', damaged: 'Damaged', expired: 'Expired', other: 'Other' };
const allowedReasons = computed(() => Object.entries(props.reasons).filter(([, dirs]) => dirs.includes(form.direction)).map(([r]) => ({ value: r, label: reasonLabels[r] ?? r })));
watch(() => form.direction, () => { if (!allowedReasons.value.some((r) => r.value === form.reason)) form.reason = allowedReasons.value[0].value; });

function submit() {
    form.transform((data) => ({
        ...data,
        items: lines.value.map((l) => ({
            product_id: l.product.id, variant_id: l.variant_id || null, quantity: l.quantity,
            unit_cost: data.direction === 'in' ? l.unit_cost : null,
            batch_number: l.batch_number || null, expiry_date: l.expiry_date || null,
        })),
    })).post('/inventory/adjustments');
}
</script>

<template>
    <Head title="New stock adjustment" />
    <PageHeader title="New stock adjustment"><Link href="/inventory/adjustments" class="btn btn-secondary">Cancel</Link></PageHeader>

    <form @submit.prevent="submit">
        <BaseCard title="Details">
            <div class="form-grid">
                <BaseSelect v-model="form.warehouse_id" label="Warehouse" placeholder="Choose…" :options="warehouses.map((w) => ({ value: w.id, label: w.name }))" :error="form.errors.warehouse_id" />
                <BaseSelect v-model="form.direction" label="Change" :options="[{ value: 'in', label: 'Increase stock' }, { value: 'out', label: 'Decrease stock' }]" :error="form.errors.direction" />
                <BaseSelect v-model="form.reason" label="Reason" :options="allowedReasons" :error="form.errors.reason" />
                <BaseInput v-model="form.adjusted_at" label="Date" type="date" required :error="form.errors.adjusted_at" />
                <BaseInput v-model="form.notes" label="Notes" :error="form.errors.notes" />
            </div>
        </BaseCard>
        <BaseCard title="Products" style="margin-top: 16px">
            <StockLinesEditor v-model="lines" :errors="form.errors" :with-cost="form.direction === 'in'" :with-batch="form.direction === 'in'" />
            <p class="hint" style="margin-bottom: 0">
                <template v-if="form.direction === 'in'">Leave the cost empty to use the current average cost. Products that track expiry need a batch number and expiry date.</template>
                <template v-else>The cost of what leaves is calculated automatically from your costing method (average, FIFO or first-expired-first-out).</template>
                Posted adjustments cannot be edited; correct a mistake with a new adjustment.
            </p>
        </BaseCard>
        <div class="row" style="margin-top: 16px; justify-content: flex-end">
            <BaseButton type="submit" :loading="form.processing" :disabled="lines.length === 0">Post adjustment</BaseButton>
        </div>
    </form>
</template>
