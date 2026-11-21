<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import PageHeader from '../../Components/Common/PageHeader.vue';
import BaseCard from '../../Components/Common/BaseCard.vue';
import EmptyState from '../../Components/Common/EmptyState.vue';
import AppIcon from '../../Components/Common/AppIcon.vue';
import MoneyDisplay from '../../Components/Common/MoneyDisplay.vue';
import BaseBadge from '../../Components/Common/BaseBadge.vue';
import { Link } from '@inertiajs/vue3';
import ReportChart from '../../Components/Charts/ReportChart.vue';
import { formatDateTime, formatQty, humanize } from '../../Utils/format';

const props = defineProps({ sales_chart: Object, stats: Object, recent_activity: Array, inventory: Object, accounts: Object, sales_today: Object });
const tenant = computed(() => usePage().props.tenant);

const user = computed(() => usePage().props.auth?.user);
const today = new Date().toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });

const cards = computed(() => [
    { label: 'Active branches', value: props.stats.branches, icon: 'branch', tone: 'violet' },
    { label: 'Active products', value: props.stats.products, icon: 'building', tone: 'teal' },
    { label: 'Team members', value: props.stats.users, icon: 'users', tone: 'sky' },
    { label: 'Active currencies', value: props.stats.currencies, icon: 'currency', tone: 'amber' },
]);
</script>

<template>
    <Head title="Dashboard" />

    <div class="hero">
        <div>
            <h2>Welcome back<template v-if="user?.name">, {{ user.name }}</template></h2>
            <p>{{ tenant?.business.name }} · {{ today }}</p>
        </div>
        <div v-if="sales_today" class="hero-figure">
            <strong><MoneyDisplay :amount="sales_today.total" /></strong>
            <span>Sales today · {{ sales_today.count }} invoices</span>
        </div>
    </div>

    <div class="grid grid-3">
        <div v-for="c in cards" :key="c.label" class="tile" :class="`tone-${c.tone}`">
            <span class="tile-icon"><AppIcon :name="c.icon" :size="22" /></span>
            <div><div class="tile-value">{{ c.value }}</div><div class="tile-label">{{ c.label }}</div></div>
        </div>
    </div>

    <template v-if="(accounts && (accounts.receivable !== null || accounts.payable !== null)) || inventory">
        <div class="section-title">Money and stock</div>
        <div class="grid grid-3">
            <Link v-if="accounts && accounts.receivable !== null" href="/customers?status=balance" class="tile tone-green"><span class="tile-icon"><AppIcon name="users" :size="22" /></span>
                <div><div class="tile-value"><MoneyDisplay :amount="accounts.receivable" /></div><div class="tile-label">Receivable from customers</div></div></Link>
            <Link v-if="accounts && accounts.payable !== null" href="/suppliers?status=balance" class="tile tone-rose"><span class="tile-icon"><AppIcon name="users" :size="22" /></span>
                <div><div class="tile-value"><MoneyDisplay :amount="accounts.payable" /></div><div class="tile-label">Payable to suppliers</div></div></Link>
            <div v-if="inventory" class="tile tone-violet"><span class="tile-icon"><AppIcon name="currency" :size="22" /></span>
                <div><div class="tile-value"><MoneyDisplay :amount="inventory.valuation.value" /></div><div class="tile-label">Stock value (at cost)</div></div></div>
            <Link v-if="inventory" href="/inventory?status=low" class="tile tone-amber"><span class="tile-icon"><AppIcon name="activity" :size="22" /></span>
                <div><div class="tile-value">{{ inventory.counts.low }}</div><div class="tile-label">Low stock products</div></div></Link>
            <Link v-if="inventory" href="/inventory?status=out" class="tile tone-rose"><span class="tile-icon"><AppIcon name="x" :size="22" /></span>
                <div><div class="tile-value">{{ inventory.counts.out }}</div><div class="tile-label">Out of stock products</div></div></Link>
        </div>
    </template>

    <BaseCard v-if="sales_chart" title="Net sales, last 14 days" style="margin-top: 18px">
        <ReportChart :chart="sales_chart" />
    </BaseCard>

    <BaseCard v-if="inventory && inventory.alerts.length" title="Stock alerts" flush style="margin-top: 18px">
        <table class="table">
            <tbody>
                <tr v-for="a in inventory.alerts" :key="a.key">
                    <td><Link :href="`/inventory/movements?product_id=${a.product_id}`"><strong>{{ a.name }}</strong></Link><span v-if="a.variant" class="muted"> · {{ a.variant }}</span></td>
                    <td class="text-right">{{ formatQty(a.quantity) }} <span class="muted">{{ a.unit }}</span></td>
                    <td class="text-right"><BaseBadge :variant="a.status === 'out' ? 'danger' : 'warning'">{{ a.status === 'out' ? 'Out of stock' : 'Low stock' }}</BaseBadge></td>
                </tr>
            </tbody>
        </table>
    </BaseCard>

    <div class="section-title">Activity</div>
    <BaseCard title="Recent activity" flush>
        <EmptyState v-if="recent_activity.length === 0" title="No activity yet" />
        <table v-else class="table">
            <tbody>
                <tr v-for="a in recent_activity" :key="a.id">
                    <td>{{ humanize(a.action) }}</td>
                    <td class="muted">{{ a.user ?? 'System' }}</td>
                    <td class="muted text-right">{{ formatDateTime(a.at, tenant?.business.timezone) }}</td>
                </tr>
            </tbody>
        </table>
    </BaseCard>
</template>
