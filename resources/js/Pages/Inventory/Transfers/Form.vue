<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '../../../Components/Common/PageHeader.vue';
import BaseCard from '../../../Components/Common/BaseCard.vue';
import BaseButton from '../../../Components/Common/BaseButton.vue';
import BaseInput from '../../../Components/Common/BaseInput.vue';
import BaseSelect from '../../../Components/Common/BaseSelect.vue';
import StockLinesEditor from '../../../Components/Inventory/StockLinesEditor.vue';

const props = defineProps({ warehouses: Array });
const today = new Date();
const localToday = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;

const lines = ref([]);
const form = useForm({ from_warehouse_id: '', to_warehouse_id: '', transferred_at: localToday, notes: '', items: [] });
const options = (exclude) => props.warehouses.filter((w) => w.id !== Number(exclude)).map((w) => ({ value: w.id, label: w.name }));

function submit() {
    form.transform((data) => ({
        ...data,
        items: lines.value.map((l) => ({ product_id: l.product.id, variant_id: l.variant_id || null, quantity: l.quantity })),
    })).post('/inventory/transfers');
}
</script>

<template>
    <Head title="New stock transfer" />
    <PageHeader title="New stock transfer"><Link href="/inventory/transfers" class="btn btn-secondary">Cancel</Link></PageHeader>
    <form @submit.prevent="submit">
        <BaseCard title="Details">
            <div class="form-grid">
                <BaseSelect v-model="form.from_warehouse_id" label="From warehouse" placeholder="Choose…" :options="options(form.to_warehouse_id)" :error="form.errors.from_warehouse_id" />
                <BaseSelect v-model="form.to_warehouse_id" label="To warehouse" placeholder="Choose…" :options="options(form.from_warehouse_id)" :error="form.errors.to_warehouse_id" />
                <BaseInput v-model="form.transferred_at" label="Date" type="date" required :error="form.errors.transferred_at" />
                <BaseInput v-model="form.notes" label="Notes" :error="form.errors.notes" />
            </div>
        </BaseCard>
        <BaseCard title="Products" style="margin-top: 16px">
            <StockLinesEditor v-model="lines" :errors="form.errors" />
            <p class="hint" style="margin-bottom: 0">Stock keeps its cost and batch/expiry when it moves. The transfer is rejected as a whole if the source warehouse does not have enough.</p>
        </BaseCard>
        <div class="row" style="margin-top: 16px; justify-content: flex-end">
            <BaseButton type="submit" :loading="form.processing" :disabled="lines.length === 0">Transfer stock</BaseButton>
        </div>
    </form>
</template>
