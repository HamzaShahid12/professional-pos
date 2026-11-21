<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '../../Components/Common/PageHeader.vue';
import BaseCard from '../../Components/Common/BaseCard.vue';
import BaseTable from '../../Components/Common/BaseTable.vue';
import BaseModal from '../../Components/Common/BaseModal.vue';
import BaseInput from '../../Components/Common/BaseInput.vue';
import BaseButton from '../../Components/Common/BaseButton.vue';
import BaseBadge from '../../Components/Common/BaseBadge.vue';
import CurrencyInput from '../../Components/Common/CurrencyInput.vue';
import { formatDateTime, trimRate } from '../../Utils/format';

const props = defineProps({ currencies: Array, catalog: Array });
const tenant = computed(() => usePage().props.tenant);
const base = computed(() => props.currencies.find((c) => c.is_base));

const columns = [
    { key: 'code', label: 'Currency' }, { key: 'symbol', label: 'Symbol' }, { key: 'exchange_rate', label: 'Current rate', align: 'right' },
    { key: 'decimal_places', label: 'Decimals' }, { key: 'is_active', label: 'Status' },
];

// --- add / edit ---
const showForm = ref(false);
const editing = ref(null);
const form = useForm({ code: '', name: '', symbol: '', decimal_places: 2, exchange_rate: '', is_active: true });

function openForm(c = null) {
    editing.value = c;
    form.clearErrors();
    form.defaults({ code: c?.code ?? '', name: c?.name ?? '', symbol: c?.symbol ?? '', decimal_places: c?.decimal_places ?? 2, exchange_rate: '', is_active: c?.is_active ?? true }).reset();
    showForm.value = true;
}
function pickCatalog(e) {
    const hit = props.catalog.find((x) => x.code === e.target.value);
    if (hit) Object.assign(form, { code: hit.code, name: hit.name, symbol: hit.symbol, decimal_places: hit.decimals });
}
function saveForm() {
    const options = { preserveScroll: true, onSuccess: () => { showForm.value = false; } };
    if (editing.value) {
        // Code and rate are immutable here; rates change only through "Update rate" so history is kept.
        form.transform(({ name, symbol, decimal_places, is_active }) => ({ name, symbol, decimal_places, is_active })).put(`/settings/currencies/${editing.value.id}`, options);
    } else {
        form.post('/settings/currencies', options);
    }
}

// --- rate update + history ---
const rateFor = ref(null);
const rateForm = useForm({ rate: '', effective_at: '' });
function openRate(c) { rateFor.value = c; rateForm.reset(); rateForm.clearErrors(); }
function saveRate() {
    rateForm.transform((d) => ({ rate: d.rate, effective_at: d.effective_at || null }))
        .post(`/settings/currencies/${rateFor.value.id}/rates`, { preserveScroll: true, onSuccess: () => { rateFor.value = null; } });
}
const historyFor = ref(null);
</script>

<template>
    <Head title="Currency settings" />
    <PageHeader title="Currency settings" crumb="Settings">
        <BaseButton @click="openForm()">Add currency</BaseButton>
    </PageHeader>

    <p class="muted" style="margin-top: -6px">
        Rates show how many <strong>{{ base?.code }}</strong> equal 1 unit of the currency. Changing a rate never alters past transactions; every rate is kept in history.
    </p>

    <BaseCard flush>
        <BaseTable :columns="columns" :rows="currencies">
            <template #cell-code="{ row }"><strong>{{ row.code }}</strong> <span class="muted">{{ row.name }}</span> <BaseBadge v-if="row.is_base" variant="info">Base</BaseBadge></template>
            <template #cell-exchange_rate="{ row }"><span class="mono">{{ row.is_base ? '1' : `1 ${row.code} = ${trimRate(row.exchange_rate)} ${base?.code}` }}</span></template>
            <template #cell-is_active="{ row }"><BaseBadge :variant="row.is_active ? 'success' : 'danger'">{{ row.is_active ? 'Active' : 'Inactive' }}</BaseBadge></template>
            <template #actions="{ row }">
                <BaseButton v-if="!row.is_base" size="sm" variant="secondary" @click="openRate(row)">Update rate</BaseButton>
                <BaseButton v-if="!row.is_base" size="sm" variant="ghost" @click="historyFor = row">History</BaseButton>
                <BaseButton size="sm" variant="ghost" @click="openForm(row)">Edit</BaseButton>
            </template>
        </BaseTable>
    </BaseCard>

    <BaseModal :show="showForm" :title="editing ? `Edit ${editing.code}` : 'Add currency'" @close="showForm = false">
        <form id="currency-form" @submit.prevent="saveForm">
            <div v-if="!editing" class="field">
                <label>Pick from list</label>
                <select class="select" @change="pickCatalog"><option value="">Custom…</option><option v-for="c in catalog" :key="c.code" :value="c.code">{{ c.code }} — {{ c.name }}</option></select>
            </div>
            <div class="form-grid">
                <BaseInput v-model="form.code" label="ISO code" :disabled="!!editing" :error="form.errors.code" required />
                <BaseInput v-model="form.name" label="Name" :error="form.errors.name" required />
                <BaseInput v-model="form.symbol" label="Symbol" :error="form.errors.symbol" required />
                <BaseInput v-model="form.decimal_places" label="Decimal places" type="number" :error="form.errors.decimal_places" required />
            </div>
            <CurrencyInput v-if="!editing" v-model="form.exchange_rate" :decimals="8" :label="`Rate (1 unit = ? ${base?.code})`" :error="form.errors.exchange_rate" required />
            <label v-if="!editing?.is_base" class="check"><input v-model="form.is_active" type="checkbox"> Active</label>
        </form>
        <template #footer>
            <BaseButton variant="secondary" @click="showForm = false">Cancel</BaseButton>
            <BaseButton type="submit" form="currency-form" :loading="form.processing">Save currency</BaseButton>
        </template>
    </BaseModal>

    <BaseModal :show="!!rateFor" :title="`Update ${rateFor?.code} rate`" size="sm" @close="rateFor = null">
        <form id="rate-form" @submit.prevent="saveRate">
            <CurrencyInput v-model="rateForm.rate" :decimals="8" :label="`1 ${rateFor?.code} = ? ${base?.code}`" :error="rateForm.errors.rate" required />
            <BaseInput v-model="rateForm.effective_at" label="Effective from (optional)" type="datetime-local" :error="rateForm.errors.effective_at" hint="Leave empty to apply now." />
        </form>
        <template #footer>
            <BaseButton variant="secondary" @click="rateFor = null">Cancel</BaseButton>
            <BaseButton type="submit" form="rate-form" :loading="rateForm.processing">Record rate</BaseButton>
        </template>
    </BaseModal>

    <BaseModal :show="!!historyFor" :title="`${historyFor?.code} rate history`" size="sm" @close="historyFor = null">
        <table class="table">
            <thead><tr><th>Effective</th><th class="text-right">Rate</th><th>Source</th></tr></thead>
            <tbody>
                <tr v-for="(h, i) in historyFor?.history" :key="i">
                    <td>{{ formatDateTime(h.effective_at, tenant?.business.timezone) }}</td>
                    <td class="text-right mono">{{ trimRate(h.rate) }}</td><td class="muted">{{ h.source }}</td>
                </tr>
            </tbody>
        </table>
        <p class="hint">Showing the most recent entries.</p>
    </BaseModal>
</template>
