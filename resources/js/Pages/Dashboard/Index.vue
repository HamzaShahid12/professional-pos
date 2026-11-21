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
import { formatDateTime, formatQty, humanize } from '../../Utils/format';

const props = defineProps({ stats: Object, recent_activity: Array, inventory: Object, accounts: Object, sales_today: Object });
const tenant = computed(() => usePage().props.tenant);

const cards = computed(() => [
    { label: 'Active branches', value: props.stats.branches, icon: 'branch' },
    { label: 'Active products', value: props.stats.products, icon: 'building' },
    { label: 'Team members', value: props.stats.users, icon: 'users' },
    { label: 'Active currencies', value: props.stats.currencies, icon: 'currency' },
]);
</script>

<template>
    <Head title="Dashboard" />
    <PageHeader title="Dashboard" />

    <div class="grid grid-3" style="margin-bottom: 16px">
        <div v-for="c in cards" :key="c.label" class="card stat">
            <span class="stat-icon"><AppIcon :name="c.icon" :size="22" /></span>
            <div><div class="stat-value">{{ c.value }}</div><div class="stat-label">{{ c.label }}</div></div>
        </div>
    </div>

    <div v-if="sales_today" class="grid grid-3" style="margin-bottom: 16px">
        <Link href="/sales" class="card stat"><span class="stat-icon"><AppIcon name="trend" :size="22" /></span>
            <div><div class="stat-value"><MoneyDisplay :amount="sales_today.total" /></div><div class="stat-label">Sales today · {{ sales_today.count }} invoices</div></div></Link>
    </div>

    <div v-if="accounts && (accounts.receivable !== null || accounts.payable !== null)" class="grid grid-3" style="margin-bottom: 16px">
        <Link v-if="accounts.receivable !== null" href="/customers?status=balance" class="card stat"><span class="stat-icon"><AppIcon name="users" :size="22" /></span>
            <div><div class="stat-value"><MoneyDisplay :amount="accounts.receivable" /></div><div class="stat-label">Receivable from customers</div></div></Link>
        <Link v-if="accounts.payable !== null" href="/suppliers?status=balance" class="card stat"><span class="stat-icon"><AppIcon name="users" :size="22" /></span>
            <div><div class="stat-value"><MoneyDisplay :amount="accounts.payable" /></div><div class="stat-label">Payable to suppliers</div></div></Link>
    </div>

    <div v-if="inventory" class="grid grid-3" style="margin-bottom: 16px">
        <div class="card stat"><span class="stat-icon"><AppIcon name="currency" :size="22" /></span>
            <div><div class="stat-value"><MoneyDisplay :amount="inventory.valuation.value" /></div><div class="stat-label">Stock value (at cost)</div></div></div>
        <Link href="/inventory?status=low" class="card stat"><span class="stat-icon"><AppIcon name="activity" :size="22" /></span>
            <div><div class="stat-value">{{ inventory.counts.low }}</div><div class="stat-label">Low stock products</div></div></Link>
        <Link href="/inventory?status=out" class="card stat"><span class="stat-icon"><AppIcon name="x" :size="22" /></span>
            <div><div class="stat-value">{{ inventory.counts.out }}</div><div class="stat-label">Out of stock products</div></div></Link>
    </div>

    <BaseCard v-if="inventory && inventory.alerts.length" title="Stock alerts" flush style="margin-bottom: 16px">
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

    <div class="grid grid-2">
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

        <BaseCard title="Getting started">
            <p style="margin-top: 0">Sales, purchasing and accounting cards appear here as those modules are enabled in later phases. Start by setting up your organisation:</p>
            <ol class="muted" style="padding-left: 18px; line-height: 1.9; margin-bottom: 0">
                <li>Add your branches under <strong>Branch Settings</strong> and review <strong>Warehouses</strong>.</li>
                <li>Create <strong>Tax Settings</strong>, categories and brands, then add or import your <strong>Products</strong>.</li>
                <li>Enter opening stock with a <strong>Stock Adjustment</strong> (reason: opening stock).</li>
                <li>Set exchange rates under <strong>Currency Settings</strong>.</li>
                <li>Invite staff under <strong>Users</strong> and assign roles and branches.</li>
            </ol>
        </BaseCard>
    </div>
</template>
