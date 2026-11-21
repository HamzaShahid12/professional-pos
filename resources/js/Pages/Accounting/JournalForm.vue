<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import PageHeader from '../../Components/Common/PageHeader.vue';
import BaseCard from '../../Components/Common/BaseCard.vue';
import BaseButton from '../../Components/Common/BaseButton.vue';
import BaseInput from '../../Components/Common/BaseInput.vue';
import MoneyDisplay from '../../Components/Common/MoneyDisplay.vue';
import AccountingTabs from '../../Components/Accounting/AccountingTabs.vue';

const props = defineProps({ accounts: Array, today: String, lock_date: String });
const blank = () => ({ account_id: '', debit: '', credit: '', memo: '' });
const form = useForm({ entry_date: props.today, description: '', lines: [blank(), blank()] });

const sum = (key) => form.lines.reduce((t, l) => t + Math.round((Number(l[key]) || 0) * 10000), 0);
const debit = computed(() => sum('debit') / 10000);
const credit = computed(() => sum('credit') / 10000);
const difference = computed(() => Math.abs(sum('debit') - sum('credit')) / 10000);
const balanced = computed(() => sum('debit') > 0 && sum('debit') === sum('credit'));

// A line is one-sided: typing a debit clears the credit.
const setSide = (l, side) => { if (l[side] !== '') l[side === 'debit' ? 'credit' : 'debit'] = ''; };
const submit = () => {
    form.transform((d) => ({ ...d, lines: d.lines.filter((l) => l.account_id || l.debit || l.credit) }));
    form.post('/accounting/journal', { preserveScroll: true });
};
</script>

<template>
    <Head title="New journal" />
    <PageHeader title="New journal"><Link href="/accounting/journal" class="btn btn-secondary">Back</Link></PageHeader>
    <AccountingTabs />
    <form @submit.prevent="submit">
        <BaseCard>
            <div class="grid grid-2">
                <BaseInput v-model="form.entry_date" type="date" label="Date" required :error="form.errors.entry_date" :hint="lock_date ? `Books are closed up to ${lock_date}.` : ''" />
                <BaseInput v-model="form.description" label="Description" required :error="form.errors.description" />
            </div>
            <p v-if="form.errors.lines" class="error-text">{{ form.errors.lines }}</p>
            <table class="table" style="margin-top: 14px">
                <thead><tr><th>Account</th><th style="width: 140px; text-align: right">Debit</th><th style="width: 140px; text-align: right">Credit</th><th>Memo</th><th style="width: 40px" /></tr></thead>
                <tbody>
                    <tr v-for="(l, i) in form.lines" :key="i">
                        <td>
                            <select v-model="l.account_id" class="select" :class="{ invalid: form.errors[`lines.${i}.account_id`] || form.errors[`lines.${i}.account`] }" :aria-label="`Account ${i + 1}`">
                                <option value="">Choose account</option>
                                <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} {{ a.name }}</option>
                            </select>
                            <span class="error-text">{{ form.errors[`lines.${i}.account_id`] || form.errors[`lines.${i}.account`] || form.errors[`lines.${i}`] }}</span>
                        </td>
                        <td><input v-model="l.debit" class="input" style="text-align: right" inputmode="decimal" :aria-label="`Debit ${i + 1}`" @input="setSide(l, 'debit')"></td>
                        <td><input v-model="l.credit" class="input" style="text-align: right" inputmode="decimal" :aria-label="`Credit ${i + 1}`" @input="setSide(l, 'credit')"></td>
                        <td><input v-model="l.memo" class="input" :aria-label="`Memo ${i + 1}`"></td>
                        <td><BaseButton v-if="form.lines.length > 2" variant="ghost" type="button" aria-label="Remove line" @click="form.lines.splice(i, 1)">×</BaseButton></td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td><BaseButton variant="secondary" type="button" @click="form.lines.push(blank())">Add line</BaseButton></td>
                        <td style="text-align: right"><strong><MoneyDisplay :amount="debit" /></strong></td>
                        <td style="text-align: right"><strong><MoneyDisplay :amount="credit" /></strong></td>
                        <td colspan="2"><span v-if="balanced" class="muted">Balanced</span><span v-else class="error-text">Difference <MoneyDisplay :amount="difference" /></span></td>
                    </tr>
                </tfoot>
            </table>
            <p class="muted">Receivable, payable and inventory are not listed: they follow customer, supplier and stock records.</p>
            <div class="row" style="justify-content: flex-end"><BaseButton type="submit" :disabled="!balanced" :loading="form.processing">Post journal</BaseButton></div>
        </BaseCard>
    </form>
</template>
