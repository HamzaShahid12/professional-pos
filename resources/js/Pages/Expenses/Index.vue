<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { reactive, ref, watch } from 'vue';
import PageHeader from '../../Components/Common/PageHeader.vue';
import BaseCard from '../../Components/Common/BaseCard.vue';
import BaseTable from '../../Components/Common/BaseTable.vue';
import BasePagination from '../../Components/Common/BasePagination.vue';
import BaseBadge from '../../Components/Common/BaseBadge.vue';
import BaseButton from '../../Components/Common/BaseButton.vue';
import BaseModal from '../../Components/Common/BaseModal.vue';
import BaseInput from '../../Components/Common/BaseInput.vue';
import BaseSelect from '../../Components/Common/BaseSelect.vue';
import SearchInput from '../../Components/Common/SearchInput.vue';
import MoneyDisplay from '../../Components/Common/MoneyDisplay.vue';

const props = defineProps({ expenses: Object, filters: Object, methods: Array, today: String, categories: Array, branches: Array, can_approve: Boolean });

const filter = reactive({ search: props.filters.search ?? '', status: props.filters.status ?? '', category_id: props.filters.category_id ?? '', from: props.filters.from ?? '', to: props.filters.to ?? '' });
let timer = null;
watch(filter, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        const params = Object.fromEntries(Object.entries(filter).filter(([, v]) => v !== ''));
        router.get('/expenses', params, { preserveState: true, preserveScroll: true, replace: true });
    }, 300);
});

const columns = [
    { key: 'reference_no', label: 'Expense' }, { key: 'expense_date', label: 'Date' }, { key: 'category', label: 'Category' },
    { key: 'description', label: 'Description' }, { key: 'method', label: 'Paid by' }, { key: 'amount', label: 'Amount', align: 'right' },
    { key: 'status', label: 'Status' }, { key: 'actions', label: '', align: 'right' },
];
const variant = { pending: 'warning', approved: 'success', rejected: 'danger', voided: 'neutral' };

const show = ref(false);
const editing = ref(null);
const form = useForm({ branch_id: '', category_id: '', expense_date: props.today, amount: '', method: 'cash', payee: '', description: '' });
const open = (e = null) => {
    editing.value = e;
    form.reset();
    form.clearErrors();
    form.expense_date = props.today;
    form.branch_id = props.branches[0]?.id ?? '';
    if (e) Object.assign(form, { branch_id: e.branch_id, category_id: e.category_id, expense_date: e.expense_date, amount: e.amount, method: e.method, payee: e.payee ?? '', description: e.description });
    show.value = true;
};
const submit = () => {
    const opts = { preserveScroll: true, onSuccess: () => { show.value = false; } };
    editing.value ? form.put(`/expenses/${editing.value.id}`, opts) : form.post('/expenses', opts);
};

const approve = (e) => router.post(`/expenses/${e.id}/approve`, {}, { preserveScroll: true });

// Reject and void both need a reason.
const reasonFor = ref(null);
const reasonForm = useForm({ reason: '' });
const askReason = (kind, e) => { reasonFor.value = { kind, expense: e }; reasonForm.reset(); reasonForm.clearErrors(); };
const sendReason = () => reasonForm.post(`/expenses/${reasonFor.value.expense.id}/${reasonFor.value.kind}`, { preserveScroll: true, onSuccess: () => { reasonFor.value = null; } });
</script>

<template>
    <Head title="Expenses" />
    <PageHeader title="Expenses">
        <Link v-if="can_approve" href="/expenses/categories" class="btn btn-secondary">Categories</Link>
        <BaseButton @click="open()">New expense</BaseButton>
    </PageHeader>

    <BaseCard flush>
        <div class="toolbar">
            <SearchInput v-model="filter.search" placeholder="Reference, description or payee" />
            <select v-model="filter.status" class="select" style="width: 130px" aria-label="Status"><option value="">Any status</option><option value="pending">Pending</option><option value="approved">Approved</option><option value="rejected">Rejected</option><option value="voided">Voided</option></select>
            <select v-model="filter.category_id" class="select" style="width: 170px" aria-label="Category"><option value="">All categories</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select>
            <input v-model="filter.from" type="date" class="input" style="width: 150px" aria-label="From date">
            <input v-model="filter.to" type="date" class="input" style="width: 150px" aria-label="To date">
        </div>
        <BaseTable :columns="columns" :rows="expenses.data" empty-title="No expenses" empty-message="Record rent, utilities, wages and other costs here.">
            <template #cell-reference_no="{ row }"><strong>{{ row.reference_no }}</strong><div class="muted">{{ row.branch }}</div></template>
            <template #cell-description="{ row }">{{ row.description }}<div v-if="row.payee" class="muted">{{ row.payee }}</div><div v-if="row.decision_note" class="muted">{{ row.decision_note }}</div></template>
            <template #cell-amount="{ row }"><MoneyDisplay :amount="row.amount" /></template>
            <template #cell-status="{ row }"><BaseBadge :variant="variant[row.status]">{{ row.status }}</BaseBadge></template>
            <template #cell-actions="{ row }">
                <template v-if="row.status === 'pending'">
                    <BaseButton v-if="row.mine || can_approve" variant="ghost" @click="open(row)">Edit</BaseButton>
                    <BaseButton v-if="can_approve" variant="ghost" @click="approve(row)">Approve</BaseButton>
                    <BaseButton v-if="can_approve" variant="ghost" @click="askReason('reject', row)">Reject</BaseButton>
                </template>
                <BaseButton v-if="row.status === 'approved' && can_approve" variant="ghost" @click="askReason('void', row)">Void</BaseButton>
            </template>
        </BaseTable>
        <BasePagination :paginator="expenses" />
    </BaseCard>

    <BaseModal :show="show" :title="editing ? `Edit ${editing.reference_no}` : 'New expense'" @close="show = false">
        <form id="expense-form" class="grid" @submit.prevent="submit">
            <p v-if="!can_approve && !editing" class="muted" style="margin: 0">This expense will wait for an approver. Nothing is paid or posted until it is approved.</p>
            <BaseSelect v-if="branches.length > 1" v-model="form.branch_id" label="Branch" :options="branches.map((b) => ({ value: b.id, label: b.name }))" :error="form.errors.branch_id" />
            <BaseSelect v-model="form.category_id" label="Category" placeholder="Choose category" :options="categories.filter((c) => c.is_active || c.id === form.category_id).map((c) => ({ value: c.id, label: c.name }))" :error="form.errors.category_id" />
            <BaseInput v-model="form.expense_date" type="date" label="Date" required :error="form.errors.expense_date || form.errors.entry_date" />
            <BaseInput v-model="form.amount" label="Amount" inputmode="decimal" required :error="form.errors.amount" />
            <BaseSelect v-model="form.method" label="Paid by" :options="methods" :error="form.errors.method" />
            <BaseInput v-model="form.payee" label="Paid to (optional)" :error="form.errors.payee" />
            <BaseInput v-model="form.description" label="Description" required :error="form.errors.description" />
        </form>
        <template #footer>
            <BaseButton variant="secondary" @click="show = false">Cancel</BaseButton>
            <BaseButton type="submit" form="expense-form" :loading="form.processing">Save</BaseButton>
        </template>
    </BaseModal>

    <BaseModal :show="!!reasonFor" :title="reasonFor?.kind === 'void' ? 'Void expense' : 'Reject expense'" @close="reasonFor = null">
        <p v-if="reasonFor?.kind === 'void'">Voiding reverses the books entry. A cash expense goes back into your open cash session.</p>
        <BaseInput v-model="reasonForm.reason" label="Reason" required :error="reasonForm.errors.reason || reasonForm.errors.status || reasonForm.errors.entry_date" />
        <template #footer>
            <BaseButton variant="secondary" @click="reasonFor = null">Cancel</BaseButton>
            <BaseButton variant="danger" :loading="reasonForm.processing" @click="sendReason">{{ reasonFor?.kind === 'void' ? 'Void' : 'Reject' }}</BaseButton>
        </template>
    </BaseModal>
</template>
