<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import MoneyDisplay from '../../Components/Common/MoneyDisplay.vue';
import { code128Bars } from '../../Utils/code128';
import { formatDateTime, formatQty, humanize } from '../../Utils/format';

// Stand-alone page: no application shell, so it prints cleanly on 58 mm, 80 mm or A4 paper.
defineOptions({ layout: null });
const props = defineProps({ sale: Object, business: Object, width: String, footer: String, developer: Object });
const tenant = computed(() => usePage().props.tenant);
const printNow = () => window.print();
const paper = computed(() => (props.width === '58' ? '58mm' : '80mm'));
const barcode = computed(() => code128Bars(props.sale.reference_no));
const paid = computed(() => props.sale.payments.reduce((s, p) => s + Number(p.amount), 0));
</script>

<template>
    <Head :title="`Receipt ${sale.reference_no}`" />
    <div class="receipt" :style="{ maxWidth: paper }">
        <header class="center">
            <h1>{{ business?.legal_name || business?.name }}</h1>
            <div v-if="business?.address">{{ business.address }}</div>
            <div v-if="business?.phone">Tel: {{ business.phone }}</div>
            <div v-if="business?.tax_number">Tax no: {{ business.tax_number }}</div>
        </header>

        <div class="dots" />

        <div class="meta">
            <div><span>Invoice:</span> {{ sale.reference_no }}</div>
            <div><span>Date:</span> {{ formatDateTime(sale.sold_at, tenant?.business?.timezone) }}</div>
            <div v-if="sale.cashier"><span>Cashier:</span> {{ sale.cashier }}</div>
            <div v-if="sale.customer"><span>Customer:</span> {{ sale.customer.name }}</div>
        </div>
        <div v-if="sale.status === 'voided'" class="center void">*** VOIDED ***</div>

        <div class="dots" />

        <table class="items">
            <thead><tr><th>Name</th><th class="r">Qty</th><th class="r">Price</th></tr></thead>
            <tbody>
                <template v-for="i in sale.items" :key="i.id">
                    <tr>
                        <td>{{ i.name }}</td>
                        <td class="r">{{ formatQty(i.quantity) }}</td>
                        <td class="r"><MoneyDisplay :amount="i.line_total" /></td>
                    </tr>
                    <tr v-if="Number(i.discount) > 0" class="sub"><td colspan="2">Discount</td><td class="r">-<MoneyDisplay :amount="i.discount" /></td></tr>
                </template>
            </tbody>
        </table>

        <div class="dots" />

        <div v-if="Number(sale.tax_total) > 0 || Number(sale.discount_total) > 0" class="lines">
            <div class="line"><span>Subtotal</span><MoneyDisplay :amount="sale.subtotal" /></div>
            <div class="line"><span>Tax</span><MoneyDisplay :amount="sale.tax_total" /></div>
        </div>
        <div class="total"><span>Price</span><span><MoneyDisplay :amount="sale.total" /></span></div>

        <div class="lines">
            <div v-for="p in sale.payments" :key="p.id" class="line"><span>{{ humanize(p.method).toUpperCase() }}</span><MoneyDisplay :amount="p.amount" /></div>
            <div v-if="Number(sale.change_due) > 0" class="line"><span>CHANGE</span><MoneyDisplay :amount="sale.change_due" /></div>
            <div v-if="Number(sale.balance_due) > 0" class="line"><strong>BALANCE DUE</strong><strong><MoneyDisplay :amount="sale.balance_due" /></strong></div>
        </div>

        <p v-if="footer" class="center pre">{{ footer }}</p>
        <div class="center thanks"><span class="dots short" /><strong>THANK YOU!</strong></div>

        <svg v-if="barcode" class="barcode" :viewBox="`0 0 ${barcode.width} 40`" preserveAspectRatio="none" role="img" :aria-label="`Barcode ${sale.reference_no}`">
            <rect v-for="(b, k) in barcode.bars" :key="k" :x="b.x" y="0" :width="b.w" height="40" />
        </svg>
        <div class="center ref">{{ sale.reference_no }}</div>

        <div v-if="developer?.name" class="center dev">
            <div>Developed by {{ developer.name }} {{ developer.name2 }}</div>
            <div v-if="developer.phone">Phone: {{ developer.phone }}</div>
        </div>

        <button class="no-print" type="button" @click="printNow">Print</button>
    </div>
</template>

<style scoped>
.receipt { margin: 0 auto; padding: 14px 12px 12px; font: 12px/1.45 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #111; background: #fff; }
.center { text-align: center; }
h1 { font-size: 19px; font-weight: 800; margin: 0 0 6px; letter-spacing: .2px; }
.dots { border-top: 2px dotted #222; margin: 10px 0; }
.dots.short { display: block; width: 62%; margin: 0 auto 6px; }
.meta { padding: 0 4px; }
.meta span { color: #444; }
.void { font-weight: 700; margin-top: 6px; }
.items { width: 100%; border-collapse: collapse; }
.items th { font-weight: 400; color: #444; text-align: left; padding: 2px 0 8px; }
.items td { padding: 2px 0; vertical-align: top; }
.items .r { text-align: right; white-space: nowrap; padding-left: 8px; }
.items .sub td { color: #555; font-size: 11px; }
.lines { padding: 0 2px; }
.line { display: flex; justify-content: space-between; gap: 8px; padding: 1px 0; }
.total { display: flex; justify-content: space-between; align-items: baseline; font-size: 21px; font-weight: 800; margin: 6px 2px 8px; }
.pre { white-space: pre-line; margin: 10px 0 0; color: #333; }
.thanks { margin: 12px 0 8px; font-size: 13px; }
.barcode { display: block; width: 78%; height: 46px; margin: 8px auto 2px; fill: #000; }
.ref { font-size: 10.5px; letter-spacing: 1px; color: #333; }
.dev { margin-top: 14px; padding-top: 8px; border-top: 1px dotted #888; font-size: 10.5px; color: #444; }
button { width: 100%; padding: 10px; margin-top: 12px; cursor: pointer; }
@media print { .no-print { display: none; } .receipt { padding: 0; } }
</style>