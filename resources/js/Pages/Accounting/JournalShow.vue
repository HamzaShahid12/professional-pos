<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '../../Components/Common/PageHeader.vue';
import BaseCard from '../../Components/Common/BaseCard.vue';
import BaseTable from '../../Components/Common/BaseTable.vue';
import BaseBadge from '../../Components/Common/BaseBadge.vue';
import BaseButton from '../../Components/Common/BaseButton.vue';
import BaseModal from '../../Components/Common/BaseModal.vue';
import BaseInput from '../../Components/Common/BaseInput.vue';
import MoneyDisplay from '../../Components/Common/MoneyDisplay.vue';
import AccountingTabs from '../../Components/Accounting/AccountingTabs.vue';

const props = defineProps({ entry: Object, can_reverse: Boolean });
const today = (() => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; })();
const show = ref(false);
const form = useForm({ entry_date: today });
const submit = () => form.post(`/accounting/journal/${props.entry.id}/reverse`, { onSuccess: () => { show.value = false; } });
const columns = [{ key: 'account', label: 'Account' }, { key: 'memo', label: 'Memo' }, { key: 'debit', label: 'Debit', align: 'right' }, { key: 'credit', label: 'Credit', align: 'right' }];
const variant = { auto: 'info', manual: 'success', reversal: 'warning' };
const shown = (v) => (Number(v) === 0 ? null : v);
</script>

<template>
    <Head :title="entry.entry_no" />
    <PageHeader :title="entry.entry_no">
        <Link v-if="entry.source_url" :href="entry.source_url" class="btn btn-secondary">Open document</Link>
        <BaseButton v-if="can_reverse" variant="danger" @click="show = true">Reverse</BaseButton>
    </PageHeader>
    <AccountingTabs />
    <BaseCard>
        <div class="grid grid-3">
            <div><div class="muted">Date</div><strong>{{ entry.entry_date }}</strong></div>
            <div><div class="muted">Type</div><BaseBadge :variant="variant[entry.kind]">{{ entry.kind }}</BaseBadge></div>
            <div><div class="muted">Posted by</div><strong>{{ entry.user ?? 'System' }}</strong></div>
        </div>
        <p>{{ entry.description }}</p>
        <p v-if="entry.reversal" class="muted">Reversed by <Link :href="`/accounting/journal/${entry.reversal.id}`">{{ entry.reversal.entry_no }}</Link>.</p>
        <p v-if="entry.reverses" class="muted">Reverses <Link :href="`/accounting/journal/${entry.reverses.id}`">{{ entry.reverses.entry_no }}</Link>.</p>
    </BaseCard>
    <BaseCard flush style="margin-top: 16px">
        <BaseTable :columns="columns" :rows="entry.lines" empty-title="No lines">
            <template #cell-account="{ row }"><Link :href="`/accounting/accounts/${row.account_id}/ledger`">{{ row.account }}</Link></template>
            <template #cell-debit="{ row }"><MoneyDisplay v-if="shown(row.debit)" :amount="row.debit" /></template>
            <template #cell-credit="{ row }"><MoneyDisplay v-if="shown(row.credit)" :amount="row.credit" /></template>
        </BaseTable>
    </BaseCard>
    <BaseModal :show="show" title="Reverse journal" @close="show = false">
        <p>A reversing entry swaps every debit and credit. The original stays on record.</p>
        <BaseInput v-model="form.entry_date" type="date" label="Reversal date" :error="form.errors.entry_date || form.errors.entry" />
        <template #footer>
            <BaseButton variant="secondary" @click="show = false">Cancel</BaseButton>
            <BaseButton variant="danger" :loading="form.processing" @click="submit">Reverse</BaseButton>
        </template>
    </BaseModal>
</template>
