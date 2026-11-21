<script setup>
import { Head } from '@inertiajs/vue3';
import CrudPage from '../../Components/Common/CrudPage.vue';

defineProps({ brands: Array });

const columns = [
    { key: 'name', label: 'Brand' }, { key: 'description', label: 'Description' },
    { key: 'products_count', label: 'Products', align: 'right' }, { key: 'is_active', label: 'Status' },
];
const fields = [
    { key: 'name', label: 'Name', type: 'text', required: true },
    { key: 'description', label: 'Description', type: 'textarea' },
    { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
];
</script>

<template>
    <Head title="Brands" />
    <CrudPage title="Brands" noun="brand" base-url="/brands" :rows="brands" :columns="columns" :fields="fields" />
</template>
