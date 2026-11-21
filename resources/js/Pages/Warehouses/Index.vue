<script setup>
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import CrudPage from '../../Components/Common/CrudPage.vue';
import BaseBadge from '../../Components/Common/BaseBadge.vue';

const props = defineProps({ warehouses: Array, branches: Array });

const columns = [
    { key: 'name', label: 'Warehouse' }, { key: 'code', label: 'Code' }, { key: 'branch', label: 'Branch' }, { key: 'is_active', label: 'Status' },
];
const fields = computed(() => [
    { key: 'name', label: 'Name', type: 'text', required: true },
    { key: 'code', label: 'Code', type: 'text', required: true, hint: 'Unique within your business, e.g. WH-MAIN' },
    { key: 'branch_id', label: 'Branch', type: 'select', options: props.branches.map((b) => ({ value: b.id, label: b.name })) },
    { key: 'address', label: 'Address', type: 'textarea' },
    { key: 'is_default', label: 'Default warehouse', type: 'checkbox' },
    { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
]);
</script>

<template>
    <Head title="Warehouses" />
    <CrudPage title="Warehouses" noun="warehouse" base-url="/warehouses" :rows="warehouses" :columns="columns" :fields="fields" :can-delete="(w) => !w.is_default"
              intro="Warehouses hold stock. A warehouse that holds stock or has stock history cannot be deleted; deactivate it instead.">
        <template #cell-name="{ row }">{{ row.name }} <BaseBadge v-if="row.is_default" variant="info">Default</BaseBadge></template>
    </CrudPage>
</template>
