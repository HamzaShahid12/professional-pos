<script setup>
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import CrudPage from '../../Components/Common/CrudPage.vue';

const props = defineProps({ categories: Array });

const columns = [
    { key: 'name', label: 'Category' }, { key: 'parent', label: 'Parent' },
    { key: 'products_count', label: 'Products', align: 'right' }, { key: 'is_active', label: 'Status' },
];
const fields = computed(() => [
    { key: 'name', label: 'Name', type: 'text', required: true },
    { key: 'parent_id', label: 'Parent category', type: 'select', options: props.categories.map((c) => ({ value: c.id, label: c.name })) },
    { key: 'description', label: 'Description', type: 'textarea' },
    { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
]);
</script>

<template>
    <Head title="Categories" />
    <CrudPage title="Categories" noun="category" base-url="/categories" :rows="categories" :columns="columns" :fields="fields"
              intro="Group products into categories and sub-categories. A category in use cannot be deleted." />
</template>
