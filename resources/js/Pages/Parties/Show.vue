<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import PageHeader from '../../Components/Common/PageHeader.vue';
import BaseCard from '../../Components/Common/BaseCard.vue';
import BaseTable from '../../Components/Common/BaseTable.vue';
import BasePagination from '../../Components/Common/BasePagination.vue';
import BaseModal from '../../Components/Common/BaseModal.vue';
import BaseButton from '../../Components/Common/BaseButton.vue';
import BaseBadge from '../../Components/Common/BaseBadge.vue';
import BaseInput from '../../Components/Common/BaseInput.vue';
import BaseSelect from '../../Components/Common/BaseSelect.vue';
import MoneyDisplay from '../../Components/Common/MoneyDisplay.vue';
import { useConfirm } from '../../Composables/useConfirm';
import { humanize } from '../../Utils/format';

const props = defineProps({ type: String, party: Object, statement: Object, methods: Array });
const { confirm } = useConfirm();
const isCustomer = computed(() => props.type === 'customer');
const base = computed(() => (isCustomer.value ? '/customers' : '/suppliers'));
const noun = computed(() => (isCustomer.value ? 'customer' : 'supplier'));

const localToday = (() => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; })();

// statement range
const range = reactive({ from: props.statement.filters.from ?? '', to: props.statement.filters.to ?? '' });
watch(range, () => {
    const params = Object.fromEntries(Object.entries(range).filter(([, v]) => v));
    router.get(`${base.value}/${props.party.id}`, params, { preserveState: true, preserveScroll: true, replace: true });
});

const columns = [
    { key: 'date', label: 'Date' }, { key: 'type', label: 'Type' }, { key: 'description', label: 'Description' },
    { key: 'amount', label: 'Amount', align: 'right' }, { key: 'balance_after', label: 'Balance', align: 'right' },
];

// receipt / payment
const showPay = ref(false);
const pay = useForm({ amount: '', method: 'cash', method_reference: '', paid_at: localToday, notes: '' });
const submitPay = () => pay.post(`${base.value}/${props.party.id}/payments`, { preserveScroll: true, onSuccess: () => { showPay.value = false; pay.reset(); pay.paid_at = localToday; } });

// adjustment
const showAdj = ref(false);
const adj = useForm({ amount: '', entry_date: localToday, reason: '' });
const submitAdj = () => adj.post(`${base.value}/${props.party.id}/adjustments`, { preserveScroll: true, onSuccess: () => { showAdj.value = false; adj.reset(); adj.entry_date = localToday; } });

// edit
const showEdit = ref(false);
const edit = useForm({
    name: props.party.name, phone: props.party.phone ?? '', email: props.party.email ?? '', address: props.party.address ?? '',
    tax_number: props.party.tax_number ?? '', notes: props.party.notes ?? '', credit_limit: props.party.credit_limit ?? '',
    contact_person: props.party.contact_person ?? '', payment_terms_days: props.party.payment_terms_days ?? '', is_active: props.party.is_active,
});
const submitEdit = () => edit.put(`${base.value}/${props.party.id}`, { preserveScroll: true, onSuccess: () => { showEdit.value = false; } });

async function archive() {
    if (await confirm({ title: `Archive ${noun.value}`, message: `Archive "${props.party.name}"? History is kept. Only accounts with a zero balance can be archived.`, confirmText: 'Archive', danger: true })) {
        router.delete(`${base.value}/${props.party.id}`);
    }
}

const balanceLabel = computed(() => {
    const b = Number(props.party.balance);
    if (b === 0) return 'Settled';
    if (isCustomer.value) return b > 0 ? 'Owes you' : 'Advance held';
    return b > 0 ? 'You owe' : 'Advance paid';
});
</script>

<template>
    <Head :title="party.name" />
    <PageHeader :title="party.name">
        <Link :href="base" class="btn btn-secondary">All {{ noun }}s</Link>
        <BaseButton variant="secondary" @click="showEdit = true">Edit</BaseButton>
        <BaseButton variant="secondary" @click="showAdj = true">Adjust balance</BaseButton>
        <BaseButton @click="showPay = true">{{ isCustomer ? 'Receive payment' : 'Pay supplier' }}</BaseButton>
    </PageHeader>

    <div class="grid grid-3" style="margin-bottom: 16px">
        <div class="card stat"><div><div class="stat-value"><MoneyDisplay :amount="party.balance" /></div><div class="stat-label">{{ balanceLabel }}</div></div></div>
        <div v-if="isCustomer" class="card stat"><div><div class="stat-value"><MoneyDisplay :amount="party.credit_limit" /></div>
            <div class="stat-label">Credit limit · <MoneyDisplay :amount="party.credit_available" /> available</div></div></div>
        <div v-else class="card stat"><div><div class="stat-value">{{ party.payment_terms_days ? `${party.payment_terms_days} days` : 'Immediate' }}</div><div class="stat-label">Payment terms</div></div></div>
        <div class="card stat"><div><div class="stat-value" style="font-size: 16px">{{ party.code }}</div>
            <div class="stat-label">{{ party.phone ?? 'No phone' }} · {{ party.email ?? 'No email' }}</div>
            <BaseBadge :variant="party.is_active ? 'success' : 'neutral'">{{ party.is_active ? 'Active' : 'Inactive' }}</BaseBadge></div></div>
    </div>

    <BaseCard title="Statement" flush>
        <div class="toolbar">
            <input v-model="range.from" type="date" class="input" style="width: 150px" aria-label="From date">
            <input v-model="range.to" type="date" class="input" style="width: 150px" aria-label="To date">
            <span class="muted">Brought forward: <MoneyDisplay :amount="statement.opening" /> · Closing: <strong><MoneyDisplay :amount="statement.closing" /></strong></span>
            <BaseButton v-if="party.balance === '0.0000'" variant="ghost" size="sm" @click="archive">Archive</BaseButton>
        </div>
        <BaseTable :columns="columns" :rows="statement.entries.data" empty-title="No ledger entries" empty-message="Entries appear here as sales, purchases and payments are recorded.">
            <template #cell-type="{ row }"><BaseBadge :variant="Number(row.amount) < 0 ? 'success' : 'info'">{{ humanize(row.type) }}</BaseBadge></template>
            <template #cell-description="{ row }">{{ row.description ?? '—' }}</template>
            <template #cell-amount="{ row }"><strong><MoneyDisplay :amount="row.amount" /></strong></template>
            <template #cell-balance_after="{ row }"><MoneyDisplay :amount="row.balance_after" /></template>
        </BaseTable>
        <BasePagination :paginator="statement.entries" />
    </BaseCard>

    <BaseModal :show="showPay" :title="isCustomer ? 'Receive payment' : 'Pay supplier'" @close="showPay = false">
        <form id="pay-form" @submit.prevent="submitPay">
            <div class="form-grid">
                <BaseInput v-model="pay.amount" label="Amount" type="number" min="0" step="0.01" required :error="pay.errors.amount" hint="More than the balance becomes an advance." />
                <BaseSelect v-model="pay.method" label="Method" :options="methods.map((m) => ({ value: m, label: humanize(m) }))" :error="pay.errors.method" />
                <BaseInput v-model="pay.method_reference" label="Cheque / transaction no." :error="pay.errors.method_reference" />
                <BaseInput v-model="pay.paid_at" label="Date" type="date" required :error="pay.errors.paid_at" />
                <BaseInput v-model="pay.notes" label="Notes" :error="pay.errors.notes" />
            </div>
        </form>
        <template #footer>
            <BaseButton variant="secondary" @click="showPay = false">Cancel</BaseButton>
            <BaseButton type="submit" form="pay-form" :loading="pay.processing">Record</BaseButton>
        </template>
    </BaseModal>

    <BaseModal :show="showAdj" title="Adjust balance" @close="showAdj = false">
        <form id="adj-form" @submit.prevent="submitAdj">
            <div class="form-grid">
                <BaseInput v-model="adj.amount" label="Amount (+ raises, − lowers)" type="number" step="0.01" required :error="adj.errors.amount" />
                <BaseInput v-model="adj.entry_date" label="Date" type="date" required :error="adj.errors.entry_date" />
                <BaseInput v-model="adj.reason" label="Reason" required :error="adj.errors.reason" />
            </div>
            <p class="hint" style="margin: 0">Entries are permanent. To undo a mistake, post an opposite adjustment.</p>
        </form>
        <template #footer>
            <BaseButton variant="secondary" @click="showAdj = false">Cancel</BaseButton>
            <BaseButton type="submit" form="adj-form" :loading="adj.processing">Post adjustment</BaseButton>
        </template>
    </BaseModal>

    <BaseModal :show="showEdit" :title="`Edit ${noun}`" @close="showEdit = false">
        <form id="edit-form" @submit.prevent="submitEdit">
            <div class="form-grid">
                <BaseInput v-model="edit.name" label="Name" required :error="edit.errors.name" />
                <BaseInput v-if="!isCustomer" v-model="edit.contact_person" label="Contact person" :error="edit.errors.contact_person" />
                <BaseInput v-model="edit.phone" label="Phone" :error="edit.errors.phone" />
                <BaseInput v-model="edit.email" label="Email" type="email" :error="edit.errors.email" />
                <BaseInput v-model="edit.tax_number" label="Tax number" :error="edit.errors.tax_number" />
                <BaseInput v-if="isCustomer" v-model="edit.credit_limit" label="Credit limit" type="number" min="0" step="0.01" :error="edit.errors.credit_limit" />
                <BaseInput v-else v-model="edit.payment_terms_days" label="Payment terms (days)" type="number" min="0" :error="edit.errors.payment_terms_days" />
                <BaseInput v-model="edit.address" label="Address" :error="edit.errors.address" />
                <BaseInput v-model="edit.notes" label="Notes" :error="edit.errors.notes" />
                <label class="row"><input v-model="edit.is_active" type="checkbox"> Active</label>
            </div>
        </form>
        <template #footer>
            <BaseButton variant="secondary" @click="showEdit = false">Cancel</BaseButton>
            <BaseButton type="submit" form="edit-form" :loading="edit.processing">Save</BaseButton>
        </template>
    </BaseModal>
</template>
