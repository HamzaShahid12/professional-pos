<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '../../Components/Common/PageHeader.vue';
import BaseCard from '../../Components/Common/BaseCard.vue';
import BaseTable from '../../Components/Common/BaseTable.vue';
import BasePagination from '../../Components/Common/BasePagination.vue';
import BaseModal from '../../Components/Common/BaseModal.vue';
import BaseInput from '../../Components/Common/BaseInput.vue';
import BaseButton from '../../Components/Common/BaseButton.vue';
import BaseBadge from '../../Components/Common/BaseBadge.vue';
import SearchInput from '../../Components/Common/SearchInput.vue';
import { useDebouncedSearch } from '../../Composables/useDebouncedSearch';
import { useConfirm } from '../../Composables/useConfirm';
import { formatDateTime } from '../../Utils/format';

const props = defineProps({ users: Object, filters: Object, roles: Array, branches: Array });
const { search } = useDebouncedSearch('/users', props.filters.search);
const { confirm } = useConfirm();

const columns = [
    { key: 'name', label: 'User' }, { key: 'roles', label: 'Roles' }, { key: 'branches', label: 'Branches' },
    { key: 'last_login_at', label: 'Last sign-in' }, { key: 'is_active', label: 'Status' },
];

const show = ref(false);
const editing = ref(null);
const form = useForm({ name: '', email: '', phone: '', password: '', is_active: true, roles: [], branch_ids: [] });

function open(user = null) {
    editing.value = user;
    form.clearErrors();
    form.defaults({
        name: user?.name ?? '', email: user?.email ?? '', phone: user?.phone ?? '', password: '',
        is_active: user?.is_active ?? true, roles: [...(user?.roles ?? [])], branch_ids: [...(user?.branch_ids ?? [])],
    }).reset();
    show.value = true;
}

function save() {
    const options = { preserveScroll: true, onSuccess: () => { show.value = false; } };
    editing.value ? form.put(`/users/${editing.value.id}`, options) : form.post('/users', options);
}

async function toggle(user) {
    const deactivating = user.is_active;
    const ok = await confirm({
        title: deactivating ? 'Deactivate user' : 'Reactivate user',
        message: deactivating ? `${user.name} will be signed out and unable to log in.` : `${user.name} will be able to sign in again.`,
        confirmText: deactivating ? 'Deactivate' : 'Reactivate', danger: deactivating,
    });
    if (!ok) return;
    useForm({ name: user.name, email: user.email, phone: user.phone ?? '', is_active: !user.is_active }).put(`/users/${user.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Users" />
    <PageHeader title="Users"><BaseButton @click="open()">Add user</BaseButton></PageHeader>

    <BaseCard flush>
        <div class="toolbar"><SearchInput v-model="search" placeholder="Search name or email" /></div>
        <BaseTable :columns="columns" :rows="users.data" empty-title="No users found">
            <template #cell-name="{ row }"><strong>{{ row.name }}</strong><div class="muted">{{ row.email }}</div></template>
            <template #cell-roles="{ row }"><BaseBadge v-for="r in row.roles" :key="r" variant="info" style="margin-right: 4px">{{ r }}</BaseBadge><span v-if="!row.roles.length" class="muted">—</span></template>
            <template #cell-branches="{ row }">{{ row.branches.length ? row.branches.join(', ') : '—' }}</template>
            <template #cell-last_login_at="{ row }">{{ formatDateTime(row.last_login_at) }}</template>
            <template #cell-is_active="{ row }"><BaseBadge :variant="row.is_active ? 'success' : 'danger'">{{ row.is_active ? 'Active' : 'Inactive' }}</BaseBadge></template>
            <template #actions="{ row }">
                <template v-if="!row.is_super_admin || $page.props.auth.user.roles.includes('Super Admin')">
                    <BaseButton size="sm" variant="secondary" @click="open(row)">Edit</BaseButton>
                    <BaseButton v-if="row.id !== $page.props.auth.user.id" size="sm" variant="ghost" @click="toggle(row)">{{ row.is_active ? 'Deactivate' : 'Reactivate' }}</BaseButton>
                </template>
            </template>
        </BaseTable>
        <BasePagination :paginator="users" />
    </BaseCard>

    <BaseModal :show="show" :title="editing ? 'Edit user' : 'Add user'" @close="show = false">
        <form id="user-form" @submit.prevent="save">
            <div class="form-grid">
                <BaseInput v-model="form.name" label="Full name" :error="form.errors.name" required />
                <BaseInput v-model="form.email" label="Email" type="email" :error="form.errors.email" required />
                <BaseInput v-model="form.phone" label="Phone" :error="form.errors.phone" />
                <BaseInput v-model="form.password" label="Password" type="password" autocomplete="new-password" :required="!editing" :error="form.errors.password" :hint="editing ? 'Leave blank to keep the current password.' : 'At least 8 characters with letters and numbers.'" />
            </div>
            <div class="field">
                <label>Roles</label>
                <div class="perm-list"><label v-for="r in roles" :key="r"><input v-model="form.roles" type="checkbox" :value="r"> {{ r }}</label></div>
                <span v-if="form.errors.roles" class="error-text">{{ form.errors.roles }}</span>
            </div>
            <div class="field">
                <label>Branch access</label>
                <div class="perm-list"><label v-for="b in branches" :key="b.id"><input v-model="form.branch_ids" type="checkbox" :value="b.id"> {{ b.name }}</label></div>
                <span class="hint">Owners, admins and Super Admins can see every branch regardless of this list.</span>
            </div>
            <label class="check"><input v-model="form.is_active" type="checkbox"> Account active</label>
            <span v-if="form.errors.is_active" class="error-text">{{ form.errors.is_active }}</span>
        </form>
        <template #footer>
            <BaseButton variant="secondary" @click="show = false">Cancel</BaseButton>
            <BaseButton type="submit" form="user-form" :loading="form.processing">Save user</BaseButton>
        </template>
    </BaseModal>
</template>
