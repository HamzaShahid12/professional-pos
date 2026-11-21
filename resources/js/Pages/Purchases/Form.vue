<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '../../Components/Common/PageHeader.vue';
import BaseCard from '../../Components/Common/BaseCard.vue';
import BaseButton from '../../Components/Common/BaseButton.vue';
import BaseInput from '../../Components/Common/BaseInput.vue';
import BaseSelect from '../../Components/Common/BaseSelect.vue';
import StockLinesEditor from '../../Components/Inventory/StockLinesEditor.vue';
import { humanize } from '../../Utils/format';

const props = defineProps({ purchase: Object, suppliers: Array, warehouses: Array, currencies: Array, methods: Array, can_receive: Boolean });
const editing = computed(() => !!props.purchase);
const localToday = (() => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; })();

const lines = ref(props.purchase?.items ?? []);
const baseCurrency = props.currencies.find((c) => c.is_base);
const form = useForm({
    supplier_id: props.purchase?.supplier_id ?? '',
    warehouse_id: props.purchase?.warehouse_id ?? (props.warehouses.length === 1 ? props.warehouses[0].id : ''),
    currency_id: props.purchase?.currency_id ?? baseCurrency?.id ?? '',
    order_date: props.purchase?.order_date ?? localToday, supplier_invoice_no: props.purchase?.supplier_invoice_no ?? '', notes: props.purchase?.notes ?? '',
    receive_now: false, paid_amount: '', payment_method: 'cash', items: [],
});

function submit(receiveNow) {
    form.transform((d) => ({
        ...d, receive_now: receiveNow, paid_amount: receiveNow ? d.paid_amount || null : null,
        items: lines.value.map((l) => ({
            product_id: l.product.id, variant_id: l.variant_id || null, quantity: l.quantity, unit_cost: l.unit_cost,
            discount: l.discount || null, batch_number: l.batch_number || null, expiry_date: l.expiry_date || null,
        })),
    }));
    editing.value ? form.put(`/purchases/${props.purchase.id}`) : form.post('/purchases');
}
</script>

<template>
    <Head :title="editing ? `Edit ${purchase.reference_no}` : 'New purchase'" />
    <PageHeader :title="editing ? `Edit ${purchase.reference_no}` : 'New purchase'"><Link href="/purchases" class="btn btn-secondary">Cancel</Link></PageHeader>

    <form @submit.prevent="submit(false)">
        <BaseCard title="Details">
            <div class="form-grid">
                <BaseSelect v-model="form.supplier_id" label="Supplier" placeholder="Choose…" :options="suppliers.map((s) => ({ value: s.id, label: s.name }))" :error="form.errors.supplier_id" />
                <BaseSelect v-model="form.warehouse_id" label="Receive into" placeholder="Choose…" :options="warehouses.map((w) => ({ value: w.id, label: w.name }))" :error="form.errors.warehouse_id" />
                <BaseSelect v-model="form.currency_id" label="Currency" :options="currencies.map((c) => ({ value: c.id, label: `${c.code} — ${c.symbol}` }))" :error="form.errors.currency_id" />
                <BaseInput v-model="form.order_date" label="Date" type="date" required :error="form.errors.order_date" />
                <BaseInput v-model="form.supplier_invoice_no" label="Supplier invoice no." :error="form.errors.supplier_invoice_no" />
                <BaseInput v-model="form.notes" label="Notes" :error="form.errors.notes" />
            </div>
        </BaseCard>
        <BaseCard title="Products" style="margin-top: 16px">
            <StockLinesEditor v-model="lines" :errors="form.errors" with-cost with-discount with-batch />
            <p class="hint" style="margin-bottom: 0">Costs are in the document currency and include tax when the product's tax is inclusive. Totals and tax are calculated by the server when you save. The exchange rate is fixed when the goods are received.</p>
        </BaseCard>
        <BaseCard v-if="can_receive && !editing" title="Receive now (optional)" style="margin-top: 16px">
            <div class="form-grid">
                <BaseInput v-model="form.paid_amount" label="Paid now" type="number" min="0" step="0.01" :error="form.errors.paid_amount" hint="Leave empty to owe the full amount to the supplier." />
                <BaseSelect v-model="form.payment_method" label="Payment method" :options="methods.map((m) => ({ value: m, label: humanize(m) }))" />
            </div>
        </BaseCard>
        <div class="row" style="margin-top: 16px; justify-content: flex-end">
            <BaseButton type="submit" variant="secondary" :loading="form.processing" :disabled="lines.length === 0">{{ editing ? 'Save changes' : 'Save as order' }}</BaseButton>
            <BaseButton v-if="can_receive && !editing" type="button" :loading="form.processing" :disabled="lines.length === 0" @click="submit(true)">Save and receive stock</BaseButton>
        </div>
    </form>
</template>
