<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { usePermissions } from '../../Composables/usePermissions';

const { can } = usePermissions();
const path = computed(() => usePage().url.split('?')[0]);
const tabs = [
    { href: '/accounting', label: 'Chart of accounts' },
    { href: '/accounting/journal', label: 'Journal' },
    { href: '/accounting/trial-balance', label: 'Trial balance' },
    { href: '/accounting/income-statement', label: 'Profit & loss' },
    { href: '/accounting/balance-sheet', label: 'Balance sheet' },
];
const active = (t) => (t.href === '/accounting' ? path.value === '/accounting' || path.value.includes('/ledger') : path.value.startsWith(t.href));
</script>

<template>
    <nav v-if="can('accounting.view')" class="toolbar" style="border: 0; padding: 0 0 14px; gap: 8px" aria-label="Accounting sections">
        <Link v-for="t in tabs" :key="t.href" :href="t.href" class="btn" :class="active(t) ? 'btn-primary' : 'btn-secondary'">{{ t.label }}</Link>
    </nav>
</template>
