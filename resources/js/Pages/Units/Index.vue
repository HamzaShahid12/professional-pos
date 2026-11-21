<script setup>
import { Head } from '@inertiajs/vue3';
import CrudPage from '../../Components/Common/CrudPage.vue';

defineProps({ units: Array });

const columns = [
    { key: 'name', label: 'Unit' }, { key: 'short_name', label: 'Short name' }, { key: 'allow_decimal', label: 'Decimal quantities' },
    { key: 'products_count', label: 'Products', align: 'right' }, { key: 'is_active', label: 'Status' },
];
const fields = [
    { key: 'name', label: 'Name', type: 'text', required: true },
    { key: 'short_name', label: 'Short name', type: 'text', required: true, hint: 'Shown on receipts and used in imports, e.g. pcs, kg.' },
    { key: 'allow_decimal', label: 'Allow decimal quantities (kg, litre, metre…)', type: 'checkbox' },
    { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
];
</script>

<template>
    <Head title="Units" />
    <CrudPage title="Units" noun="unit" base-url="/units" :rows="units" :columns="columns" :fields="fields"
              intro="Units whose products can be sold in fractions (kg, litre) must allow decimal quantities." />
</template>
