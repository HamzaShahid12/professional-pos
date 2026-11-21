<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '../../Components/Common/PageHeader.vue';
import BaseCard from '../../Components/Common/BaseCard.vue';
import BaseTable from '../../Components/Common/BaseTable.vue';
import BasePagination from '../../Components/Common/BasePagination.vue';
import BaseBadge from '../../Components/Common/BaseBadge.vue';
import BaseButton from '../../Components/Common/BaseButton.vue';
import BaseModal from '../../Components/Common/BaseModal.vue';
import BaseInput from '../../Components/Common/BaseInput.vue';
import BaseSelect from '../../Components/Common/BaseSelect.vue';
import MoneyDisplay from '../../Components/Common/MoneyDisplay.vue';

const props = defineProps({ registers: Array, mine: Object, sessions: Object, branches: Array, can_configure: Boolean });

const openFor = ref(null);
const openForm = useForm({ opening_float: '0' });
const startOpen = (r) => { openFor.value = r; openForm.reset(); openForm.clearErrors(); };
const submitOpen = () => openForm.post(`/cash-register/registers/${openFor.value.id}/open`, { onSuccess: () => { openFor.value = null; } });

const showRegister = ref(false);
const editing = ref(null);
const regForm = useForm({ name: '', branch_id: '', is_active: true });
const editRegister = (r = null) => {
    editing.value = r;
    regForm.reset();
    regForm.clearErrors();
    regForm.branch_id = props.branches[0]?.id ?? '';
    if (r) Object.assign(regForm, { name: r.name, branch_id: r.branch_id, is_active: r.is_active });
    showRegister.value = true;
};
const submitRegister = () => {
    const opts = { preserveScroll: true, onSuccess: () => { showRegister.value = false; } };
    editing.value ? regForm.put(`/cash-register/registers/${editing.value.id}`, opts) : regForm.post('/cash-register/registers', opts);
};

const columns = [
    { key: 'reference_no', label: 'Session' }, { key: 'register', label: 'Register' }, { key: 'cashier', label: 'Cashier' },
    { key: 'opened_at', label: 'Opened' }, { key: 'status', label: 'Status' }, { key: 'difference', label: 'Difference', align: 'right' },
];
const when = (iso) => (iso ? new Date(iso).toLocaleString() : '—');
</script>

<template>
    <Head title="Cash register" />
    <PageHeader title="Cash register"><BaseButton v-if="can_configure" @click="editRegister()">New register</BaseButton></PageHeader>

    <BaseCard v-if="mine" title="Your open session" style="margin-bottom: 16px">
        <div class="grid grid-3">
            <div><div class="muted">Session</div><strong>{{ mine.reference_no }} · {{ mine.register }}</strong></div>
            <div><div class="muted">Opened</div><strong>{{ when(mine.opened_at) }}</strong></div>
            <div><div class="muted">Cash that should be in the drawer</div><strong><MoneyDisplay :amount="mine.summary.expected" /></strong></div>
        </div>
        <div class="row" style="margin-top: 12px"><Link :href="`/cash-register/sessions/${mine.id}`" class="btn">Pay in, pay out or close</Link></div>
    </BaseCard>

    <BaseCard title="Registers" flush style="margin-bottom: 16px">
        <table class="table">
            <thead><tr><th>Register</th><th>Branch</th><th>Status</th><th /></tr></thead>
            <tbody>
                <tr v-if="!registers.length"><td colspan="4" class="muted">No registers yet.<template v-if="can_configure"> Create one to start taking cash by shift.</template></td></tr>
                <tr v-for="r in registers" :key="r.id">
                    <td><strong>{{ r.name }}</strong></td>
                    <td>{{ r.branch }}</td>
                    <td><BaseBadge :variant="!r.is_active ? 'neutral' : r.open_session_id ? 'info' : 'success'">{{ !r.is_active ? 'Off' : r.open_session_id ? 'In use' : 'Free' }}</BaseBadge></td>
                    <td style="text-align: right">
                        <BaseButton v-if="r.is_active && !r.open_session_id && !mine" variant="secondary" @click="startOpen(r)">Open session</BaseButton>
                        <Link v-if="r.open_session_id" :href="`/cash-register/sessions/${r.open_session_id}`" class="btn btn-ghost">View</Link>
                        <BaseButton v-if="can_configure" variant="ghost" @click="editRegister(r)">Edit</BaseButton>
                    </td>
                </tr>
            </tbody>
        </table>
    </BaseCard>

    <BaseCard title="Sessions" flush>
        <BaseTable :columns="columns" :rows="sessions.data" empty-title="No sessions yet" empty-message="A session starts when a cashier opens a register.">
            <template #cell-reference_no="{ row }"><Link :href="`/cash-register/sessions/${row.id}`"><strong>{{ row.reference_no }}</strong></Link></template>
            <template #cell-opened_at="{ row }">{{ when(row.opened_at) }}</template>
            <template #cell-status="{ row }"><BaseBadge :variant="row.status === 'open' ? 'info' : 'neutral'">{{ row.status }}</BaseBadge></template>
            <template #cell-difference="{ row }"><MoneyDisplay v-if="row.difference !== null" :amount="row.difference" /></template>
        </BaseTable>
        <BasePagination :paginator="sessions" />
    </BaseCard>

    <BaseModal :show="!!openFor" :title="`Open ${openFor?.name}`" @close="openFor = null">
        <BaseInput v-model="openForm.opening_float" label="Opening float (cash already in the drawer)" inputmode="decimal" :error="openForm.errors.opening_float || openForm.errors.register" />
        <template #footer>
            <BaseButton variant="secondary" @click="openFor = null">Cancel</BaseButton>
            <BaseButton :loading="openForm.processing" @click="submitOpen">Open session</BaseButton>
        </template>
    </BaseModal>

    <BaseModal :show="showRegister" :title="editing ? 'Edit register' : 'New register'" @close="showRegister = false">
        <form id="register-form" class="grid" @submit.prevent="submitRegister">
            <BaseInput v-model="regForm.name" label="Name" required :error="regForm.errors.name" />
            <BaseSelect v-model="regForm.branch_id" label="Branch" :options="branches.map((b) => ({ value: b.id, label: b.name }))" :error="regForm.errors.branch_id" />
            <label v-if="editing" class="check"><input v-model="regForm.is_active" type="checkbox"> Active</label>
        </form>
        <template #footer>
            <BaseButton variant="secondary" @click="showRegister = false">Cancel</BaseButton>
            <BaseButton type="submit" form="register-form" :loading="regForm.processing">Save</BaseButton>
        </template>
    </BaseModal>
</template>
