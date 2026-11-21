<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '../../Components/Common/PageHeader.vue';
import BaseCard from '../../Components/Common/BaseCard.vue';
import BaseTable from '../../Components/Common/BaseTable.vue';
import BaseModal from '../../Components/Common/BaseModal.vue';
import BaseInput from '../../Components/Common/BaseInput.vue';
import BaseSelect from '../../Components/Common/BaseSelect.vue';
import BaseButton from '../../Components/Common/BaseButton.vue';
import BaseBadge from '../../Components/Common/BaseBadge.vue';
import { useConfirm } from '../../Composables/useConfirm';

defineProps({ branches: Array, users: Array });
const { confirm } = useConfirm();

const columns = [
    { key: 'name', label: 'Branch' }, { key: 'code', label: 'Code' }, { key: 'manager', label: 'Manager' },
    { key: 'phone', label: 'Phone' }, { key: 'is_active', label: 'Status' },
];

const show = ref(false);
const editing = ref(null);
const form = useForm({ name: '', code: '', address: '', phone: '', email: '', manager_id: '', is_main: false, is_active: true, user_ids: [] });

function open(branch = null) {
    editing.value = branch;
    form.clearErrors();
    form.defaults({
        name: branch?.name ?? '', code: branch?.code ?? '', address: branch?.address ?? '', phone: branch?.phone ?? '',
        email: branch?.email ?? '', manager_id: branch?.manager_id ?? '', is_main: branch?.is_main ?? false,
        is_active: branch?.is_active ?? true, user_ids: [...(branch?.user_ids ?? [])],
    }).reset();
    show.value = true;
}

function save() {
    const payload = form.transform((d) => ({ ...d, manager_id: d.manager_id || null }));
    const options = { preserveScroll: true, onSuccess: () => { show.value = false; } };
    editing.value ? payload.put(`/settings/branches/${editing.value.id}`, options) : payload.post('/settings/branches', options);
}

async function remove(branch) {
    if (await confirm({ title: 'Delete branch', message: `Delete "${branch.name}"? Its history stays on record.`, confirmText: 'Delete', danger: true })) {
        router.delete(`/settings/branches/${branch.id}`, { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Branch settings" />
    <PageHeader title="Branch settings" crumb="Settings">
        <BaseButton @click="open()">Add branch</BaseButton>
    </PageHeader>

    <BaseCard flush>
        <BaseTable :columns="columns" :rows="branches" empty-title="No branches yet">
            <template #cell-name="{ row }">{{ row.name }} <BaseBadge v-if="row.is_main" variant="info">Main</BaseBadge></template>
            <template #cell-manager="{ row }">{{ row.manager ?? '—' }}</template>
            <template #cell-phone="{ row }">{{ row.phone ?? '—' }}</template>
            <template #cell-is_active="{ row }"><BaseBadge :variant="row.is_active ? 'success' : 'danger'">{{ row.is_active ? 'Active' : 'Inactive' }}</BaseBadge></template>
            <template #actions="{ row }">
                <BaseButton size="sm" variant="secondary" @click="open(row)">Edit</BaseButton>
                <BaseButton v-if="!row.is_main" size="sm" variant="ghost" @click="remove(row)">Delete</BaseButton>
            </template>
        </BaseTable>
    </BaseCard>

    <BaseModal :show="show" :title="editing ? 'Edit branch' : 'Add branch'" @close="show = false">
        <form id="branch-form" @submit.prevent="save">
            <div class="form-grid">
                <BaseInput v-model="form.name" label="Branch name" :error="form.errors.name" required />
                <BaseInput v-model="form.code" label="Code" :error="form.errors.code" hint="Unique within your business, e.g. MAIN" required />
                <BaseInput v-model="form.phone" label="Phone" :error="form.errors.phone" />
                <BaseInput v-model="form.email" label="Email" type="email" :error="form.errors.email" />
            </div>
            <BaseInput v-model="form.address" label="Address" :error="form.errors.address" />
            <BaseSelect v-model="form.manager_id" label="Branch manager" placeholder="No manager" :options="users.map((u) => ({ value: u.id, label: u.name }))" :error="form.errors.manager_id" />

            <div class="field">
                <label>Assigned staff</label>
                <div class="perm-list">
                    <label v-for="u in users" :key="u.id"><input v-model="form.user_ids" type="checkbox" :value="u.id"> {{ u.name }}</label>
                </div>
            </div>
            <label class="check"><input v-model="form.is_main" type="checkbox" :disabled="editing?.is_main"> Main branch</label>
            <label class="check"><input v-model="form.is_active" type="checkbox" :disabled="editing?.is_main"> Active</label>
            <span v-if="form.errors.is_main" class="error-text">{{ form.errors.is_main }}</span>
            <span v-if="form.errors.is_active" class="error-text">{{ form.errors.is_active }}</span>
        </form>
        <template #footer>
            <BaseButton variant="secondary" @click="show = false">Cancel</BaseButton>
            <BaseButton type="submit" form="branch-form" :loading="form.processing">Save branch</BaseButton>
        </template>
    </BaseModal>
</template>
