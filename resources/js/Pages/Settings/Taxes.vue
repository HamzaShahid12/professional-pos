<script setup>
import { Head } from '@inertiajs/vue3';
import CrudPage from '../../Components/Common/CrudPage.vue';
import BaseBadge from '../../Components/Common/BaseBadge.vue';
import { trimRate } from '../../Utils/format';

defineProps({ taxes: Array });

const columns = [
    { key: 'name', label: 'Tax' }, { key: 'rate', label: 'Rate', align: 'right' },
    { key: 'is_inclusive', label: 'Type' }, { key: 'is_active', label: 'Status' },
];
const fields = [
    { key: 'name', label: 'Name', type: 'text', required: true },
    { key: 'rate', label: 'Rate (%)', type: 'number', required: true },
    { key: 'is_inclusive', label: 'Inclusive (already contained in the selling price)', type: 'checkbox' },
    { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
];
</script>

<template>
    <Head title="Tax settings" />
    <CrudPage title="Tax settings" crumb="Settings" noun="tax" base-url="/settings/taxes" :rows="taxes" :columns="columns" :fields="fields"
              intro="Exclusive taxes are added on top of the price; inclusive taxes are extracted from it. Taxes are always calculated on the server. Changing a rate never alters invoices already issued.">
        <template #cell-rate="{ value }"><span class="mono">{{ trimRate(value) }}%</span></template>
        <template #cell-is_inclusive="{ value }"><BaseBadge :variant="value ? 'info' : 'neutral'">{{ value ? 'Inclusive' : 'Exclusive' }}</BaseBadge></template>
    </CrudPage>
</template>
