<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '../../Components/Common/PageHeader.vue';
import BaseCard from '../../Components/Common/BaseCard.vue';
import BaseTable from '../../Components/Common/BaseTable.vue';
import BaseBadge from '../../Components/Common/BaseBadge.vue';
import BaseButton from '../../Components/Common/BaseButton.vue';
import BaseModal from '../../Components/Common/BaseModal.vue';
import BaseInput from '../../Components/Common/BaseInput.vue';
import BaseSelect from '../../Components/Common/BaseSelect.vue';
import MoneyDisplay from '../../Components/Common/MoneyDisplay.vue';
import AccountingTabs from '../../Components/Accounting/AccountingTabs.vue';
import { useConfirm } from '../../Composables/useConfirm';

const props = defineProps({ accounts: Array, types: Array, lock_date: String, can_manage: Boolean });
const { confirm } = useConfirm();

const columns = [
    { key: 'code', label: 'Code' }, { key: 'name', label: 'Account' }, { key: 'type', label: 'Type' },
    { key: 'balance', label: 'Balance', align: 'right' }, { key: 'actions', label: '', align: 'right' },
];

const show = ref(false);
const editing = ref(null);
const form = useForm({ code: '', name: '', type: 'expense', parent_id: '', description: '', is_active: true });
const groups = computed(() => props.accounts.filter((a) => !a.parent_id && a.type === form.type && a.id !== editing.value?.id));

const open = (a = null) => {
    editing.value = a;
    form.reset();
    form.clearErrors();
    if (a) Object.assign(form, { code: a.code, name: a.name, type: a.type, parent_id: a.parent_id ?? '', description: a.description ?? '', is_active: a.is_active });
    show.value = true;
};
const submit = () => {
    const opts = { preserveScroll: true, onSuccess: () => { show.value = false; } };
    form.transform((d) => ({ ...d, parent_id: d.parent_id || null }));
    editing.value ? form.put(`/accounting/accounts/${editing.value.id}`, opts) : form.post('/accounting/accounts', opts);
};
const remove = async (a) => {
    if (await confirm({ title: 'Delete account', message: `Delete ${a.code} ${a.name}?`, confirmText: 'Delete', danger: true })) {
        router.delete(`/accounting/accounts/${a.id}`, { preserveScroll: true });
    }
};

const lock = useForm({ lock_date: props.lock_date ?? '' });
const saveLock = () => lock.post('/accounting/lock', { preserveScroll: true });
const typeVariant = { asset: 'info', liability: 'warning', equity: 'neutral', revenue: 'success', expense: 'danger' };
</script>

<template>
    <Head title="Chart of accounts" />
    <PageHeader title="Accounting"><BaseButton v-if="can_manage" @click="open()">New account</BaseButton></PageHeader>
    <AccountingTabs />

    <BaseCard flush>
        <BaseTable :columns="columns" :rows="accounts" empty-title="No accounts" empty-message="The standard chart is created automatically.">
            <template #cell-code="{ row }"><span class="mono" :style="{ paddingLeft: row.parent_id ? '16px' : 0 }">{{ row.code }}</span></template>
            <template #cell-name="{ row }">
                <Link :href="`/accounting/accounts/${row.id}/ledger`"><strong>{{ row.name }}</strong></Link>
                <BaseBadge v-if="!row.is_active" variant="neutral" style="margin-left: 6px">Inactive</BaseBadge>
            </template>
            <template #cell-type="{ row }"><BaseBadge :variant="typeVariant[row.type]">{{ row.type }}</BaseBadge></template>
            <template #cell-balance="{ row }"><MoneyDisplay :amount="row.balance" /></template>
            <template #cell-actions="{ row }">
                <template v-if="can_manage">
                    <BaseButton variant="ghost" @click="open(row)">Edit</BaseButton>
                    <BaseButton v-if="!row.is_system" variant="ghost" @click="remove(row)">Delete</BaseButton>
                </template>
            </template>
        </BaseTable>
    </BaseCard>

    <BaseCard v-if="can_manage" title="Close the books" style="margin-top: 16px">
        <p class="muted" style="margin-top: 0">Nothing can be posted, reversed or voided on or before the closing date. A closed period can be extended, never reopened.</p>
        <form class="row" style="align-items: flex-end" @submit.prevent="saveLock">
            <BaseInput v-model="lock.lock_date" type="date" label="Books closed up to" :error="lock.errors.lock_date" />
            <BaseButton type="submit" :loading="lock.processing">Save</BaseButton>
        </form>
    </BaseCard>

    <BaseModal :show="show" :title="editing ? 'Edit account' : 'New account'" @close="show = false">
        <form id="account-form" class="grid" @submit.prevent="submit">
            <BaseInput v-model="form.code" label="Code" required :error="form.errors.code" />
            <BaseInput v-model="form.name" label="Name" required :error="form.errors.name" />
            <BaseSelect v-model="form.type" label="Type" :options="types" :error="form.errors.type" :disabled="editing && (editing.is_system || editing.balance !== '0.0000')" />
            <BaseSelect v-model="form.parent_id" label="Group (optional)" placeholder="None" :options="groups.map((g) => ({ value: g.id, label: `${g.code} ${g.name}` }))" :error="form.errors.parent_id" />
            <BaseInput v-model="form.description" label="Description" :error="form.errors.description" />
            <label v-if="editing && !editing.is_system" class="row"><input v-model="form.is_active" type="checkbox"> Active</label>
        </form>
        <template #footer>
            <BaseButton variant="secondary" @click="show = false">Cancel</BaseButton>
            <BaseButton type="submit" form="account-form" :loading="form.processing">Save</BaseButton>
        </template>
    </BaseModal>
</template>
