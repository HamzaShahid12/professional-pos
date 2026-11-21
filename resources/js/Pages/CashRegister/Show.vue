<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '../../Components/Common/PageHeader.vue';
import BaseCard from '../../Components/Common/BaseCard.vue';
import BaseBadge from '../../Components/Common/BaseBadge.vue';
import BaseButton from '../../Components/Common/BaseButton.vue';
import BaseModal from '../../Components/Common/BaseModal.vue';
import BaseInput from '../../Components/Common/BaseInput.vue';
import BaseSelect from '../../Components/Common/BaseSelect.vue';
import MoneyDisplay from '../../Components/Common/MoneyDisplay.vue';

const props = defineProps({ session: Object, summary: Object, movements: Array, can_operate: Boolean });
const labels = { sale: 'Cash sales', refund: 'Cash refunds', expense: 'Cash expenses', pay_in: 'Pay-ins', pay_out: 'Pay-outs' };
const typeLabel = { sale: 'Sale', refund: 'Refund', expense: 'Expense', pay_in: 'Pay-in', pay_out: 'Pay-out' };
const when = (iso) => (iso ? new Date(iso).toLocaleString() : '—');

const showMove = ref(false);
const move = useForm({ direction: 'in', counter: 'owner', amount: '', notes: '' });
const submitMove = () => move.post(`/cash-register/sessions/${props.session.id}/movements`, { preserveScroll: true, onSuccess: () => { showMove.value = false; move.reset(); } });

const showClose = ref(false);
const close = useForm({ counted_cash: '', notes: '' });
const submitClose = () => close.post(`/cash-register/sessions/${props.session.id}/close`);
</script>

<template>
    <Head :title="session.reference_no" />
    <PageHeader :title="`${session.reference_no} · ${session.register}`">
        <Link href="/cash-register" class="btn btn-secondary">Back</Link>
        <template v-if="can_operate">
            <BaseButton variant="secondary" @click="showMove = true">Pay in / pay out</BaseButton>
            <BaseButton @click="showClose = true">Close session</BaseButton>
        </template>
    </PageHeader>

    <BaseCard>
        <div class="grid grid-3">
            <div><div class="muted">Cashier</div><strong>{{ session.cashier }}</strong></div>
            <div><div class="muted">Opened</div><strong>{{ when(session.opened_at) }}</strong></div>
            <div><div class="muted">Status</div><BaseBadge :variant="session.status === 'open' ? 'info' : 'neutral'">{{ session.status }}</BaseBadge></div>
            <div v-if="session.closed_at"><div class="muted">Closed</div><strong>{{ when(session.closed_at) }}</strong><span v-if="session.closer" class="muted"> by {{ session.closer }}</span></div>
        </div>
        <p v-if="session.notes" class="muted">{{ session.notes }}</p>
    </BaseCard>

    <BaseCard title="Drawer" style="margin-top: 16px">
        <table class="table">
            <tbody>
                <tr><td>Opening float</td><td style="text-align: right"><MoneyDisplay :amount="summary.float" /></td></tr>
                <tr v-for="(label, key) in labels" :key="key"><td>{{ label }}</td><td style="text-align: right"><MoneyDisplay :amount="summary.by_type[key]" /></td></tr>
                <tr><th>Should be in the drawer</th><th style="text-align: right"><MoneyDisplay :amount="summary.expected" /></th></tr>
                <template v-if="session.status === 'closed'">
                    <tr><td>Counted</td><td style="text-align: right"><MoneyDisplay :amount="session.counted_cash" /></td></tr>
                    <tr><th>Difference</th><th style="text-align: right"><MoneyDisplay :amount="session.difference" /></th></tr>
                </template>
            </tbody>
        </table>
    </BaseCard>

    <BaseCard title="Movements" flush style="margin-top: 16px">
        <table class="table">
            <thead><tr><th>Time</th><th>Type</th><th>Note</th><th style="text-align: right">Amount</th></tr></thead>
            <tbody>
                <tr v-if="!movements.length"><td colspan="4" class="muted">Nothing yet.</td></tr>
                <tr v-for="m in movements" :key="m.id">
                    <td>{{ when(m.at) }}</td>
                    <td>{{ typeLabel[m.type] }}<span v-if="m.counter" class="muted"> ({{ m.counter }})</span></td>
                    <td>{{ m.notes }}</td>
                    <td style="text-align: right"><MoneyDisplay :amount="m.amount" /></td>
                </tr>
            </tbody>
        </table>
    </BaseCard>

    <BaseModal :show="showMove" title="Pay in or pay out" @close="showMove = false">
        <form id="move-form" class="grid" @submit.prevent="submitMove">
            <BaseSelect v-model="move.direction" label="Direction" :options="[{ value: 'in', label: 'Cash in' }, { value: 'out', label: 'Cash out' }]" />
            <BaseSelect v-model="move.counter" label="With" :options="[{ value: 'owner', label: 'Owner (capital in, drawings out)' }, { value: 'bank', label: 'Bank (withdrawal in, deposit out)' }]" />
            <BaseInput v-model="move.amount" label="Amount" inputmode="decimal" required :error="move.errors.amount" />
            <BaseInput v-model="move.notes" label="Reason" required :error="move.errors.notes || move.errors.session || move.errors.entry_date" />
        </form>
        <template #footer>
            <BaseButton variant="secondary" @click="showMove = false">Cancel</BaseButton>
            <BaseButton type="submit" form="move-form" :loading="move.processing">Record</BaseButton>
        </template>
    </BaseModal>

    <BaseModal :show="showClose" title="Close session" @close="showClose = false">
        <p>Count the cash in the drawer. It should be <strong><MoneyDisplay :amount="summary.expected" /></strong>; any difference is booked to cash over/short.</p>
        <BaseInput v-model="close.counted_cash" label="Counted cash" inputmode="decimal" required :error="close.errors.counted_cash || close.errors.session || close.errors.entry_date" />
        <BaseInput v-model="close.notes" label="Notes (optional)" :error="close.errors.notes" />
        <template #footer>
            <BaseButton variant="secondary" @click="showClose = false">Cancel</BaseButton>
            <BaseButton :loading="close.processing" @click="submitClose">Close session</BaseButton>
        </template>
    </BaseModal>
</template>
