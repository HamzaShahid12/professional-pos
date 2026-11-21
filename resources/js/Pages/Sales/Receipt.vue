<script setup>
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import MoneyDisplay from '../../Components/Common/MoneyDisplay.vue';
import { formatDateTime, formatQty, humanize } from '../../Utils/format';

// Stand-alone page: no application shell, so it prints cleanly on 58 mm, 80 mm or A4 paper.
defineOptions({ layout: null });
const props = defineProps({ sale: Object, business: Object, width: String, footer: String });
const tenant = computed(() => usePage().props.tenant);
const printNow = () => window.print();
const paper = computed(() => (props.width === '58' ? '58mm' : '80mm'));
</script>

<template>
    <Head :title="`Receipt ${sale.reference_no}`" />
    <div class="receipt" :style="{ maxWidth: paper }">
        <header style="text-align: center">
            <h1>{{ business?.legal_name || business?.name }}</h1>
            <div v-if="business?.address">{{ business.address }}</div>
            <div v-if="business?.phone">{{ business.phone }}</div>
            <div v-if="business?.tax_number">Tax no: {{ business.tax_number }}</div>
        </header>
        <hr>
        <div class="row-between"><span>Invoice</span><strong>{{ sale.reference_no }}</strong></div>
        <div class="row-between"><span>Date</span><span>{{ formatDateTime(sale.sold_at, tenant?.business?.timezone) }}</span></div>
        <div v-if="sale.customer" class="row-between"><span>Customer</span><span>{{ sale.customer.name }}</span></div>
        <div class="row-between"><span>Cashier</span><span>{{ sale.cashier }}</span></div>
        <div v-if="sale.status === 'voided'" style="text-align: center; font-weight: 700; margin: 6px 0">*** VOIDED ***</div>
        <hr>
        <div v-for="i in sale.items" :key="i.id" style="margin-bottom: 6px">
            <div>{{ i.name }}</div>
            <div class="row-between"><span>{{ formatQty(i.quantity) }} × <MoneyDisplay :amount="i.unit_price" /></span><span><MoneyDisplay :amount="i.line_total" /></span></div>
            <div v-if="Number(i.discount) > 0" class="row-between"><span>&nbsp;&nbsp;Discount</span><span>-<MoneyDisplay :amount="i.discount" /></span></div>
        </div>
        <hr>
        <div class="row-between"><span>Subtotal</span><MoneyDisplay :amount="sale.subtotal" /></div>
        <div class="row-between"><span>Tax</span><MoneyDisplay :amount="sale.tax_total" /></div>
        <div class="row-between" style="font-size: 1.15em"><strong>TOTAL</strong><strong><MoneyDisplay :amount="sale.total" /></strong></div>
        <div v-for="p in sale.payments" :key="p.id" class="row-between"><span>{{ humanize(p.method) }}</span><MoneyDisplay :amount="p.amount" /></div>
        <div v-if="Number(sale.change_due) > 0" class="row-between"><span>Change</span><MoneyDisplay :amount="sale.change_due" /></div>
        <div v-if="Number(sale.balance_due) > 0" class="row-between"><strong>Balance due</strong><strong><MoneyDisplay :amount="sale.balance_due" /></strong></div>
        <hr>
        <p v-if="footer" style="text-align: center; white-space: pre-line">{{ footer }}</p>
        <p style="text-align: center">Thank you!</p>
        <button class="no-print" type="button" style="width: 100%; padding: 10px; margin-top: 8px" @click="printNow">Print</button>
    </div>
</template>

<style scoped>
.receipt { margin: 0 auto; padding: 8px; font: 12px/1.4 ui-monospace, Menlo, Consolas, monospace; color: #000; background: #fff; }
.receipt h1 { font-size: 15px; margin: 0 0 4px; }
.row-between { display: flex; justify-content: space-between; gap: 8px; }
hr { border: 0; border-top: 1px dashed #000; margin: 8px 0; }
@media print { .no-print { display: none; } }
</style>
