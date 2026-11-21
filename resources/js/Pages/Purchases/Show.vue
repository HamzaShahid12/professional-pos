<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import PageHeader from '../../Components/Common/PageHeader.vue';
import BaseCard from '../../Components/Common/BaseCard.vue';
import BaseTable from '../../Components/Common/BaseTable.vue';
import BaseBadge from '../../Components/Common/BaseBadge.vue';
import BaseButton from '../../Components/Common/BaseButton.vue';
import BaseModal from '../../Components/Common/BaseModal.vue';
import BaseInput from '../../Components/Common/BaseInput.vue';
import BaseSelect from '../../Components/Common/BaseSelect.vue';
import MoneyDisplay from '../../Components/Common/MoneyDisplay.vue';
import { usePermissions } from '../../Composables/usePermissions';
import { useConfirm } from '../../Composables/useConfirm';
import { formatQty, humanize } from '../../Utils/format';

const props = defineProps({ purchase: Object, methods: Array });
const { can } = usePermissions();
const { confirm } = useConfirm();
const localToday = (() => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; })();
const variant = { ordered: 'info', received: 'success', cancelled: 'neutral' };
const cur = computed(() => props.purchase.currency);

const columns = [
    { key: 'product', label: 'Product' }, { key: 'quantity', label: 'Qty', align: 'right' }, { key: 'unit_cost', label: 'Unit cost', align: 'right' },
    { key: 'discount', label: 'Discount', align: 'right' }, { key: 'tax_amount', label: 'Tax', align: 'right' }, { key: 'line_total', label: 'Total', align: 'right' },
];

// receive
const showReceive = ref(false);
const recv = useForm({ received_at: localToday, paid_amount: '', payment_method: 'cash' });
const submitReceive = () => recv.post(`/purchases/${props.purchase.id}/receive`, { preserveScroll: true, onSuccess: () => { showReceive.value = false; } });

// return
const showReturn = ref(false);
const qtys = reactive({});
const ret = useForm({ return_date: localToday, notes: '', items: [] });
const returnable = (i) => Number(i.quantity) - Number(i.returned_qty);
const submitReturn = () => {
    ret.transform((d) => ({
        ...d, items: Object.entries(qtys).filter(([, q]) => Number(q) > 0).map(([id, q]) => ({ purchase_item_id: Number(id), quantity: q })),
    })).post(`/purchases/${props.purchase.id}/returns`, { preserveScroll: true, onSuccess: () => { showReturn.value = false; Object.keys(qtys).forEach((k) => delete qtys[k]); } });
};

async function cancel() {
    if (await confirm({ title: 'Cancel purchase', message: 'The order is cancelled. No stock or supplier balance is affected.', confirmText: 'Cancel order', danger: true })) {
        router.post(`/purchases/${props.purchase.id}/cancel`, {}, { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="purchase.reference_no" />
    <PageHeader :title="purchase.reference_no">
        <Link href="/purchases" class="btn btn-secondary">All purchases</Link>
        <template v-if="purchase.status === 'ordered'">
            <BaseButton v-if="can('purchases.update')" variant="ghost" @click="cancel">Cancel order</BaseButton>
            <Link v-if="can('purchases.update')" :href="`/purchases/${purchase.id}/edit`" class="btn btn-secondary">Edit</Link>
            <BaseButton v-if="can('purchases.receive')" @click="showReceive = true">Receive goods</BaseButton>
        </template>
        <BaseButton v-if="purchase.status === 'received' && can('purchases.return')" variant="secondary" @click="showReturn = true">Return to supplier</BaseButton>
    </PageHeader>

    <BaseCard style="margin-bottom: 16px">
        <div class="row" style="gap: 28px; flex-wrap: wrap">
            <div><div class="muted">Supplier</div><Link v-if="purchase.supplier" :href="`/suppliers/${purchase.supplier.id}`"><strong>{{ purchase.supplier.name }}</strong></Link></div>
            <div><div class="muted">Warehouse</div><strong>{{ purchase.warehouse }}</strong></div>
            <div><div class="muted">Order date</div><strong>{{ purchase.order_date }}</strong></div>
            <div v-if="purchase.received_at"><div class="muted">Received</div><strong>{{ purchase.received_at }}</strong></div>
            <div><div class="muted">Status</div><BaseBadge :variant="variant[purchase.status]">{{ purchase.status }}</BaseBadge></div>
            <div v-if="purchase.supplier_invoice_no"><div class="muted">Supplier invoice</div><strong>{{ purchase.supplier_invoice_no }}</strong></div>
            <div><div class="muted">Currency</div><strong>{{ cur.code }}</strong><span v-if="purchase.status === 'received' && cur.code" class="muted"> @ {{ Number(purchase.exchange_rate) }}</span></div>
        </div>
        <p v-if="purchase.notes" style="margin-bottom: 0">{{ purchase.notes }}</p>
    </BaseCard>

    <BaseCard flush>
        <BaseTable :columns="columns" :rows="purchase.items">
            <template #cell-product="{ row }"><strong>{{ row.product }}</strong><span v-if="row.variant" class="muted"> · {{ row.variant }}</span><div class="muted mono">{{ row.sku }}<template v-if="row.batch_number"> · batch {{ row.batch_number }}</template><template v-if="row.expiry_date"> · exp {{ row.expiry_date }}</template></div></template>
            <template #cell-quantity="{ row }">{{ formatQty(row.quantity) }}<span v-if="Number(row.returned_qty) > 0" class="muted"> ({{ formatQty(row.returned_qty) }} returned)</span></template>
            <template #cell-unit_cost="{ row }"><MoneyDisplay :amount="row.unit_cost" :currency="cur" /></template>
            <template #cell-discount="{ row }"><MoneyDisplay :amount="row.discount" :currency="cur" /></template>
            <template #cell-tax_amount="{ row }"><MoneyDisplay :amount="row.tax_amount" :currency="cur" /></template>
            <template #cell-line_total="{ row }"><MoneyDisplay :amount="row.line_total" :currency="cur" /></template>
        </BaseTable>
        <div class="card-body" style="display: grid; justify-content: end; gap: 4px; text-align: right">
            <div>Subtotal (net): <MoneyDisplay :amount="purchase.subtotal" :currency="cur" /></div>
            <div>Tax: <MoneyDisplay :amount="purchase.tax_total" :currency="cur" /></div>
            <div><strong>Total: <MoneyDisplay :amount="purchase.total" :currency="cur" /></strong></div>
            <div v-if="purchase.status === 'received'" class="muted">In base currency: <MoneyDisplay :amount="purchase.total_base" /> · paid at receiving: <MoneyDisplay :amount="purchase.paid_base" /></div>
        </div>
    </BaseCard>

    <BaseCard v-if="purchase.returns.length" title="Returns" flush style="margin-top: 16px">
        <table class="table"><tbody>
            <tr v-for="r in purchase.returns" :key="r.id"><td><strong>{{ r.reference_no }}</strong></td><td>{{ r.return_date }}</td><td class="text-right">Credit <MoneyDisplay :amount="r.total_base" /></td></tr>
        </tbody></table>
    </BaseCard>

    <BaseModal :show="showReceive" title="Receive goods" @close="showReceive = false">
        <form id="recv-form" @submit.prevent="submitReceive">
            <div class="form-grid">
                <BaseInput v-model="recv.received_at" label="Received on" type="date" :error="recv.errors.received_at" />
                <BaseInput v-model="recv.paid_amount" label="Paid now" type="number" min="0" step="0.01" :error="recv.errors.paid_amount" hint="Leave empty to owe the full amount." />
                <BaseSelect v-model="recv.payment_method" label="Method" :options="methods.map((m) => ({ value: m, label: humanize(m) }))" />
            </div>
            <p class="hint" style="margin: 0">Stock is added, the supplier is credited and the exchange rate is fixed. A received purchase can no longer be edited; use a return.</p>
        </form>
        <template #footer>
            <BaseButton variant="secondary" @click="showReceive = false">Cancel</BaseButton>
            <BaseButton type="submit" form="recv-form" :loading="recv.processing">Receive into stock</BaseButton>
        </template>
    </BaseModal>

    <BaseModal :show="showReturn" title="Return to supplier" @close="showReturn = false">
        <form id="ret-form" @submit.prevent="submitReturn">
            <BaseInput v-model="ret.return_date" label="Date" type="date" required :error="ret.errors.return_date" />
            <table class="table"><thead><tr><th>Product</th><th class="text-right">Returnable</th><th style="width: 120px">Return qty</th></tr></thead><tbody>
                <tr v-for="i in purchase.items" :key="i.id">
                    <td>{{ i.product }}<span v-if="i.variant" class="muted"> · {{ i.variant }}</span></td>
                    <td class="text-right">{{ formatQty(returnable(i)) }}</td>
                    <td><input v-model="qtys[i.id]" class="input" type="number" min="0" step="any" :disabled="returnable(i) <= 0" :aria-label="`Return quantity of ${i.product}`"></td>
                </tr>
            </tbody></table>
            <span v-if="ret.errors.items" class="error-text">{{ ret.errors.items }}</span>
            <BaseInput v-model="ret.notes" label="Notes" :error="ret.errors.notes" />
            <p class="hint" style="margin: 0">Stock is removed and the supplier's balance is credited with the price paid (tax included) for the returned quantity.</p>
        </form>
        <template #footer>
            <BaseButton variant="secondary" @click="showReturn = false">Cancel</BaseButton>
            <BaseButton type="submit" form="ret-form" :loading="ret.processing">Post return</BaseButton>
        </template>
    </BaseModal>
</template>
