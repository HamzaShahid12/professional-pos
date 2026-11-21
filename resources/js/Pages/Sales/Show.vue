<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import PageHeader from '../../Components/Common/PageHeader.vue';
import BaseCard from '../../Components/Common/BaseCard.vue';
import BaseTable from '../../Components/Common/BaseTable.vue';
import BaseBadge from '../../Components/Common/BaseBadge.vue';
import BaseButton from '../../Components/Common/BaseButton.vue';
import BaseModal from '../../Components/Common/BaseModal.vue';
import BaseInput from '../../Components/Common/BaseInput.vue';
import BaseSelect from '../../Components/Common/BaseSelect.vue';
import MoneyDisplay from '../../Components/Common/MoneyDisplay.vue';
import { formatDateTime, formatQty, humanize } from '../../Utils/format';

const props = defineProps({ sale: Object, refundMethods: Array });
const tenant = computed(() => usePage().props.tenant);
const s = computed(() => props.sale);

const columns = [
    { key: 'name', label: 'Item' }, { key: 'quantity', label: 'Qty', align: 'right' }, { key: 'unit_price', label: 'Price', align: 'right' },
    { key: 'discount', label: 'Discount', align: 'right' }, { key: 'tax_amount', label: 'Tax', align: 'right' }, { key: 'line_total', label: 'Total', align: 'right' },
];

// return
const showReturn = ref(false);
const qtys = reactive({});
const ret = useForm({ refund_method: s.value.customer ? 'account' : 'cash', notes: '', items: [] });
const returnable = (i) => Number(i.quantity) - Number(i.returned_qty);
const methodOptions = computed(() => props.refundMethods.filter((m) => m !== 'account' || s.value.customer).map((m) => ({ value: m, label: m === 'account' ? 'Credit to customer account' : `Pay back by ${m}` })));
function submitReturn() {
    ret.transform((d) => ({ ...d, items: Object.entries(qtys).filter(([, q]) => Number(q) > 0).map(([id, q]) => ({ sale_item_id: Number(id), quantity: q })) }))
        .post(`/sales/${s.value.id}/returns`, { preserveScroll: true, onSuccess: () => { showReturn.value = false; Object.keys(qtys).forEach((k) => delete qtys[k]); } });
}

// void
const showVoid = ref(false);
const voidForm = useForm({ reason: '' });
const submitVoid = () => voidForm.post(`/sales/${s.value.id}/void`, { preserveScroll: true, onSuccess: () => { showVoid.value = false; } });
</script>

<template>
    <Head :title="sale.reference_no" />
    <PageHeader :title="sale.reference_no">
        <Link href="/sales" class="btn btn-secondary">All sales</Link>
        <a :href="`/sales/${sale.id}/receipt`" target="_blank" rel="noopener" class="btn btn-secondary">Receipt</a>
        <a :href="`/sales/${sale.id}/pdf`" class="btn btn-secondary">PDF invoice</a>
        <BaseButton v-if="sale.permissions.return" variant="secondary" @click="showReturn = true">Customer return</BaseButton>
        <BaseButton v-if="sale.permissions.void" variant="ghost" @click="showVoid = true">Void sale</BaseButton>
    </PageHeader>

    <BaseCard style="margin-bottom: 16px">
        <div class="row" style="gap: 28px; flex-wrap: wrap">
            <div><div class="muted">Date</div><strong>{{ formatDateTime(sale.sold_at, tenant?.business.timezone) }}</strong></div>
            <div><div class="muted">Customer</div><Link v-if="sale.customer" :href="`/customers/${sale.customer.id}`"><strong>{{ sale.customer.name }}</strong></Link><strong v-else>Walk-in</strong></div>
            <div><div class="muted">Cashier</div><strong>{{ sale.cashier ?? '—' }}</strong></div>
            <div><div class="muted">Warehouse</div><strong>{{ sale.warehouse }}</strong></div>
            <div><div class="muted">Status</div><BaseBadge :variant="sale.status === 'voided' ? 'neutral' : 'success'">{{ sale.status }}</BaseBadge></div>
        </div>
        <p v-if="sale.void_reason" class="error-text" style="margin-bottom: 0">Voided: {{ sale.void_reason }}</p>
    </BaseCard>

    <BaseCard flush>
        <BaseTable :columns="columns" :rows="sale.items">
            <template #cell-name="{ row }"><strong>{{ row.name }}</strong><div class="muted mono">{{ row.sku }}</div></template>
            <template #cell-quantity="{ row }">{{ formatQty(row.quantity) }}<span v-if="Number(row.returned_qty) > 0" class="muted"> ({{ formatQty(row.returned_qty) }} returned)</span></template>
            <template #cell-unit_price="{ row }"><MoneyDisplay :amount="row.unit_price" /></template>
            <template #cell-discount="{ row }"><MoneyDisplay :amount="row.discount" /></template>
            <template #cell-tax_amount="{ row }"><MoneyDisplay :amount="row.tax_amount" /></template>
            <template #cell-line_total="{ row }"><MoneyDisplay :amount="row.line_total" /></template>
        </BaseTable>
        <div class="card-body" style="display: grid; justify-content: end; gap: 4px; text-align: right">
            <div>Subtotal: <MoneyDisplay :amount="sale.subtotal" /></div>
            <div>Tax: <MoneyDisplay :amount="sale.tax_total" /></div>
            <div><strong>Total: <MoneyDisplay :amount="sale.total" /></strong></div>
            <div v-for="p in sale.payments" :key="p.id" class="muted">{{ humanize(p.method) }}: <MoneyDisplay :amount="p.amount" /></div>
            <div v-if="Number(sale.change_due) > 0" class="muted">Change given: <MoneyDisplay :amount="sale.change_due" /></div>
            <div v-if="Number(sale.balance_due) > 0" class="error-text">On account: <MoneyDisplay :amount="sale.balance_due" /></div>
        </div>
    </BaseCard>

    <BaseCard v-if="sale.returns.length" title="Returns" flush style="margin-top: 16px">
        <table class="table"><tbody>
            <tr v-for="r in sale.returns" :key="r.id"><td><strong>{{ r.reference_no }}</strong></td><td>{{ r.return_date }}</td><td>{{ r.refund_method === 'account' ? 'Credited to account' : `Paid back (${r.refund_method})` }}</td><td class="text-right"><MoneyDisplay :amount="r.refund_total" /></td></tr>
        </tbody></table>
    </BaseCard>

    <BaseModal :show="showReturn" title="Customer return" @close="showReturn = false">
        <form id="ret-form" @submit.prevent="submitReturn">
            <table class="table"><thead><tr><th>Item</th><th class="text-right">Returnable</th><th style="width: 110px">Return qty</th></tr></thead><tbody>
                <tr v-for="i in sale.items" :key="i.id"><td>{{ i.name }}</td><td class="text-right">{{ formatQty(returnable(i)) }}</td>
                    <td><input v-model="qtys[i.id]" class="input" type="number" min="0" step="any" :disabled="returnable(i) <= 0" :aria-label="`Return quantity of ${i.name}`"></td></tr>
            </tbody></table>
            <span v-if="ret.errors.items" class="error-text">{{ ret.errors.items }}</span>
            <BaseSelect v-model="ret.refund_method" label="Refund" :options="methodOptions" :error="ret.errors.refund_method" />
            <BaseInput v-model="ret.notes" label="Notes" :error="ret.errors.notes" />
            <p class="hint" style="margin: 0">Items go back into stock at the cost they left with. The refund is the price charged, tax included. Cash paid back can never exceed what the customer actually paid.</p>
        </form>
        <template #footer>
            <BaseButton variant="secondary" @click="showReturn = false">Cancel</BaseButton>
            <BaseButton type="submit" form="ret-form" :loading="ret.processing">Post return</BaseButton>
        </template>
    </BaseModal>

    <BaseModal :show="showVoid" title="Void sale" @close="showVoid = false">
        <form id="void-form" @submit.prevent="submitVoid">
            <BaseInput v-model="voidForm.reason" label="Reason" required :error="voidForm.errors.reason" />
            <p class="hint" style="margin: 0">All items return to stock and the customer's account is reversed. A sale that already has returns cannot be voided.</p>
        </form>
        <template #footer>
            <BaseButton variant="secondary" @click="showVoid = false">Keep sale</BaseButton>
            <BaseButton type="submit" form="void-form" variant="danger" :loading="voidForm.processing">Void sale</BaseButton>
        </template>
    </BaseModal>
</template>
