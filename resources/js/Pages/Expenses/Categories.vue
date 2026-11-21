<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '../../Components/Common/PageHeader.vue';
import BaseCard from '../../Components/Common/BaseCard.vue';
import BaseTable from '../../Components/Common/BaseTable.vue';
import BaseBadge from '../../Components/Common/BaseBadge.vue';
import BaseButton from '../../Components/Common/BaseButton.vue';
import BaseModal from '../../Components/Common/BaseModal.vue';
import BaseInput from '../../Components/Common/BaseInput.vue';
import BaseSelect from '../../Components/Common/BaseSelect.vue';
import { useConfirm } from '../../Composables/useConfirm';

defineProps({ categories: Array, accounts: Array });
const { confirm } = useConfirm();
const columns = [{ key: 'name', label: 'Category' }, { key: 'account', label: 'Posts to' }, { key: 'is_active', label: 'Status' }, { key: 'actions', label: '', align: 'right' }];

const show = ref(false);
const editing = ref(null);
const form = useForm({ name: '', account_id: '', is_active: true });
const open = (c = null) => {
    editing.value = c;
    form.reset();
    form.clearErrors();
    if (c) Object.assign(form, { name: c.name, account_id: c.account_id, is_active: c.is_active });
    show.value = true;
};
const submit = () => {
    const opts = { preserveScroll: true, onSuccess: () => { show.value = false; } };
    editing.value ? form.put(`/expenses/categories/${editing.value.id}`, opts) : form.post('/expenses/categories', opts);
};
const remove = async (c) => {
    if (await confirm({ title: 'Delete category', message: `Delete ${c.name}?`, confirmText: 'Delete', danger: true })) router.delete(`/expenses/categories/${c.id}`, { preserveScroll: true });
};
</script>

<template>
    <Head title="Expense categories" />
    <PageHeader title="Expense categories">
        <Link href="/expenses" class="btn btn-secondary">Back to expenses</Link>
        <BaseButton @click="open()">New category</BaseButton>
    </PageHeader>
    <BaseCard flush>
        <BaseTable :columns="columns" :rows="categories" empty-title="No categories" empty-message="Create one for each kind of cost you want to track.">
            <template #cell-is_active="{ row }"><BaseBadge :variant="row.is_active ? 'success' : 'neutral'">{{ row.is_active ? 'Active' : 'Inactive' }}</BaseBadge></template>
            <template #cell-actions="{ row }">
                <BaseButton variant="ghost" @click="open(row)">Edit</BaseButton>
                <BaseButton variant="ghost" @click="remove(row)">Delete</BaseButton>
            </template>
        </BaseTable>
    </BaseCard>
    <BaseModal :show="show" :title="editing ? 'Edit category' : 'New category'" @close="show = false">
        <form id="category-form" class="grid" @submit.prevent="submit">
            <BaseInput v-model="form.name" label="Name" required :error="form.errors.name" />
            <BaseSelect v-model="form.account_id" label="Expense account" placeholder="Choose account" :options="accounts.map((a) => ({ value: a.id, label: `${a.code} ${a.name}` }))" :error="form.errors.account_id" />
            <label v-if="editing" class="check"><input v-model="form.is_active" type="checkbox"> Active</label>
            <p v-if="form.errors.category" class="error-text">{{ form.errors.category }}</p>
        </form>
        <template #footer>
            <BaseButton variant="secondary" @click="show = false">Cancel</BaseButton>
            <BaseButton type="submit" form="category-form" :loading="form.processing">Save</BaseButton>
        </template>
    </BaseModal>
</template>
