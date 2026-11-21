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
import SearchInput from '../../Components/Common/SearchInput.vue';
import MoneyDisplay from '../../Components/Common/MoneyDisplay.vue';

const props = defineProps({ type: String, parties: Object, filters: Object, totals: Object });
const isCustomer = computed(() => props.type === 'customer');
const base = computed(() => (isCustomer.value ? '/customers' : '/suppliers'));
const noun = computed(() => (isCustomer.value ? 'Customer' : 'Supplier'));

const filter = reactive({ search: props.filters.search ?? '', status: props.filters.status ?? '' });
let timer = null;
watch(filter, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        const params = Object.fromEntries(Object.entries(filter).filter(([, v]) => v !== '' && v !== null));
        router.get(base.value, params, { preserveState: true, preserveScroll: true, replace: true });
    }, 300);
});

const columns = computed(() => [
    { key: 'name', label: noun.value }, { key: 'phone', label: 'Phone' },
    isCustomer.value ? { key: 'credit_limit', label: 'Credit limit', align: 'right' } : { key: 'payment_terms_days', label: 'Terms' },
    { key: 'balance', label: isCustomer.value ? 'Owes you' : 'You owe', align: 'right' }, { key: 'is_active', label: 'Status' },
]);

const show = ref(false);
const form = useForm({
    name: '', code: '', phone: '', email: '', address: '', tax_number: '', notes: '', opening_balance: '',
    credit_limit: '', contact_person: '', payment_terms_days: '', is_active: true,
});
const submit = () => form.post(base.value, { onSuccess: () => { show.value = false; form.reset(); } });
</script>

<template>
    <Head :title="`${noun}s`" />
    <PageHeader :title="`${noun}s`"><BaseButton @click="show = true">Add {{ noun.toLowerCase() }}</BaseButton></PageHeader>

    <div class="grid grid-3" style="margin-bottom: 16px">
        <div class="card stat"><div><div class="stat-value"><MoneyDisplay :amount="totals.receivable_or_payable" /></div>
            <div class="stat-label">{{ isCustomer ? 'Total receivable (owed to you)' : 'Total payable (you owe)' }}</div></div></div>
        <div class="card stat"><div><div class="stat-value"><MoneyDisplay :amount="totals.advances" /></div>
            <div class="stat-label">{{ isCustomer ? 'Customer advances held' : 'Advances paid to suppliers' }}</div></div></div>
    </div>

    <BaseCard flush>
        <div class="toolbar">
            <SearchInput v-model="filter.search" placeholder="Name, code, phone or email" />
            <select v-model="filter.status" class="select" style="width: 170px" aria-label="Status">
                <option value="">All</option><option value="active">Active</option><option value="inactive">Inactive</option><option value="balance">With a balance</option>
            </select>
        </div>
        <BaseTable :columns="columns" :rows="parties.data" :empty-title="`No ${noun.toLowerCase()}s found`" empty-message="Add one, or change the search.">
            <template #cell-name="{ row }"><Link :href="`${base}/${row.id}`"><strong>{{ row.name }}</strong></Link><div class="muted mono">{{ row.code }}</div></template>
            <template #cell-phone="{ row }">{{ row.phone ?? '—' }}</template>
            <template #cell-credit_limit="{ row }"><MoneyDisplay :amount="row.credit_limit" /></template>
            <template #cell-payment_terms_days="{ row }">{{ row.payment_terms_days ? `${row.payment_terms_days} days` : 'Immediate' }}</template>
            <template #cell-balance="{ row }"><strong :class="Number(row.balance) < 0 ? 'error-text' : ''"><MoneyDisplay :amount="row.balance" /></strong></template>
            <template #cell-is_active="{ row }"><BaseBadge :variant="row.is_active ? 'success' : 'neutral'">{{ row.is_active ? 'Active' : 'Inactive' }}</BaseBadge></template>
        </BaseTable>
        <BasePagination :paginator="parties" />
    </BaseCard>

    <BaseModal :show="show" :title="`Add ${noun.toLowerCase()}`" @close="show = false">
        <form id="party-form" @submit.prevent="submit">
            <div class="form-grid">
                <BaseInput v-model="form.name" label="Name" required :error="form.errors.name" />
                <BaseInput v-model="form.code" label="Code" :error="form.errors.code" hint="Leave empty to generate one." />
                <BaseInput v-if="!isCustomer" v-model="form.contact_person" label="Contact person" :error="form.errors.contact_person" />
                <BaseInput v-model="form.phone" label="Phone" :error="form.errors.phone" />
                <BaseInput v-model="form.email" label="Email" type="email" :error="form.errors.email" />
                <BaseInput v-model="form.tax_number" label="Tax number" :error="form.errors.tax_number" />
                <BaseInput v-if="isCustomer" v-model="form.credit_limit" label="Credit limit" type="number" min="0" step="0.01" :error="form.errors.credit_limit" hint="0 = cash sales only." />
                <BaseInput v-else v-model="form.payment_terms_days" label="Payment terms (days)" type="number" min="0" :error="form.errors.payment_terms_days" />
                <BaseInput v-model="form.opening_balance" label="Opening balance" type="number" step="0.01" :error="form.errors.opening_balance"
                           :hint="isCustomer ? 'Positive = they already owe you. Negative = you hold their advance.' : 'Positive = you already owe them. Negative = they hold your advance.'" />
                <BaseInput v-model="form.address" label="Address" :error="form.errors.address" />
                <BaseInput v-model="form.notes" label="Notes" :error="form.errors.notes" />
            </div>
            <p class="hint" style="margin: 0">The opening balance is posted to the ledger once and cannot be edited afterwards; use a ledger adjustment instead.</p>
        </form>
        <template #footer>
            <BaseButton variant="secondary" @click="show = false">Cancel</BaseButton>
            <BaseButton type="submit" form="party-form" :loading="form.processing">Save</BaseButton>
        </template>
    </BaseModal>
</template>
