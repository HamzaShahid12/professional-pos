<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '../../Components/Common/PageHeader.vue';
import BaseCard from '../../Components/Common/BaseCard.vue';
import BaseModal from '../../Components/Common/BaseModal.vue';
import BaseInput from '../../Components/Common/BaseInput.vue';
import BaseButton from '../../Components/Common/BaseButton.vue';
import BaseBadge from '../../Components/Common/BaseBadge.vue';
import { useConfirm } from '../../Composables/useConfirm';

const props = defineProps({ roles: Array, groups: Object });
const { confirm } = useConfirm();

const selected = ref(props.roles[0] ?? null);
const form = useForm({ permissions: [...(selected.value?.permissions ?? [])] });

function select(role) {
    selected.value = role;
    form.defaults({ permissions: [...role.permissions] }).reset();
    form.clearErrors();
}
const toggleGroup = (perms, on) => {
    const set = new Set(form.permissions);
    perms.forEach((p) => (on ? set.add(p) : set.delete(p)));
    form.permissions = [...set];
};
const groupFull = (perms) => perms.every((p) => form.permissions.includes(p));

function save() {
    form.put(`/roles/${selected.value.id}`, {
        preserveScroll: true,
        onSuccess: () => { selected.value = props.roles.find((r) => r.id === selected.value.id) ?? selected.value; },
    });
}

const showNew = ref(false);
const newRole = useForm({ name: '', permissions: [] });
const createRole = () => newRole.post('/roles', { preserveScroll: true, onSuccess: () => { showNew.value = false; newRole.reset(); } });

async function remove(role) {
    if (await confirm({ title: 'Delete role', message: `Delete the "${role.name}" role?`, confirmText: 'Delete', danger: true })) {
        router.delete(`/roles/${role.id}`, { preserveScroll: true, onSuccess: () => { selected.value = props.roles[0]; } });
    }
}
</script>

<template>
    <Head title="Roles & permissions" />
    <PageHeader title="Roles & permissions"><BaseButton @click="showNew = true">New role</BaseButton></PageHeader>

    <div class="grid" style="grid-template-columns: minmax(220px, 280px) 1fr; align-items: start">
        <BaseCard title="Roles" flush>
            <button v-for="r in roles" :key="r.id" type="button" class="nav-link" :class="{ active: selected?.id === r.id }" style="width: 100%; border: 0; background: none; cursor: pointer; justify-content: space-between; color: var(--text); border-radius: 0; margin: 0" :style="selected?.id === r.id ? 'background: var(--primary-50); color: var(--primary)' : ''" @click="select(r)">
                <span>{{ r.name }}</span><span class="muted">{{ r.users_count }}</span>
            </button>
        </BaseCard>

        <BaseCard v-if="selected" :title="`${selected.name} permissions`">
            <template #header>
                <BaseBadge v-if="selected.locked" variant="warning">Always has full access</BaseBadge>
                <BaseButton v-else-if="!selected.is_system" size="sm" variant="ghost" @click="remove(selected)">Delete role</BaseButton>
            </template>

            <div v-for="(perms, group) in groups" :key="group" class="perm-group">
                <h4 class="row" style="justify-content: space-between">
                    {{ group }}
                    <button v-if="!selected.locked" type="button" class="btn btn-ghost btn-sm" @click="toggleGroup(perms, !groupFull(perms))">{{ groupFull(perms) ? 'Clear' : 'Select all' }}</button>
                </h4>
                <div class="perm-list">
                    <label v-for="p in perms" :key="p"><input v-model="form.permissions" type="checkbox" :value="p" :disabled="selected.locked"> {{ p }}</label>
                </div>
            </div>
            <p class="hint">Changes apply to every user holding this role on their next request. Permissions are enforced on the server.</p>
            <BaseButton v-if="!selected.locked" :loading="form.processing" @click="save">Save permissions</BaseButton>
        </BaseCard>
    </div>

    <BaseModal :show="showNew" title="New role" size="sm" @close="showNew = false">
        <form id="role-form" @submit.prevent="createRole">
            <BaseInput v-model="newRole.name" label="Role name" :error="newRole.errors.name" required />
            <p class="hint">Create the role, then choose its permissions.</p>
        </form>
        <template #footer>
            <BaseButton variant="secondary" @click="showNew = false">Cancel</BaseButton>
            <BaseButton type="submit" form="role-form" :loading="newRole.processing">Create role</BaseButton>
        </template>
    </BaseModal>
</template>
