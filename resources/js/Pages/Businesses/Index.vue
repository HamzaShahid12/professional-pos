<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '../../Components/Common/PageHeader.vue';
import BaseCard from '../../Components/Common/BaseCard.vue';
import BaseTable from '../../Components/Common/BaseTable.vue';
import BaseModal from '../../Components/Common/BaseModal.vue';
import BaseInput from '../../Components/Common/BaseInput.vue';
import BaseSelect from '../../Components/Common/BaseSelect.vue';
import BaseButton from '../../Components/Common/BaseButton.vue';
import BaseBadge from '../../Components/Common/BaseBadge.vue';

defineProps({ businesses: Array, currencies: Array, timezones: Array });

const columns = [
    { key: 'name', label: 'Business' }, { key: 'country', label: 'Country' }, { key: 'base_currency_code', label: 'Base currency' },
    { key: 'users_count', label: 'Users', align: 'right' }, { key: 'is_active', label: 'Status' },
];
const show = ref(false);
const form = useForm({ name: '', country: '', timezone: 'UTC', base_currency_code: 'USD', email: '', phone: '' });
const save = () => form.post('/businesses', { preserveScroll: true, onSuccess: () => { show.value = false; form.reset(); } });
</script>

<template>
    <Head title="Businesses" />
    <PageHeader title="Businesses"><BaseButton @click="show = true">New business</BaseButton></PageHeader>

    <BaseCard flush>
        <BaseTable :columns="columns" :rows="businesses" empty-title="No businesses">
            <template #cell-country="{ row }">{{ row.country ?? '—' }}</template>
            <template #cell-is_active="{ row }"><BaseBadge :variant="row.is_active ? 'success' : 'danger'">{{ row.is_active ? 'Active' : 'Inactive' }}</BaseBadge></template>
        </BaseTable>
    </BaseCard>

    <BaseModal :show="show" title="New business" @close="show = false">
        <form id="biz-form" @submit.prevent="save">
            <div class="form-grid">
                <BaseInput v-model="form.name" label="Business name" :error="form.errors.name" required />
                <BaseInput v-model="form.country" label="Country" :error="form.errors.country" />
                <BaseSelect v-model="form.base_currency_code" label="Base currency" :options="currencies.map((c) => ({ value: c.code, label: `${c.code} — ${c.name}` }))" :error="form.errors.base_currency_code" hint="Cannot be changed later." />
                <BaseSelect v-model="form.timezone" label="Timezone" :options="timezones" :error="form.errors.timezone" />
                <BaseInput v-model="form.email" label="Email" type="email" :error="form.errors.email" />
                <BaseInput v-model="form.phone" label="Phone" :error="form.errors.phone" />
            </div>
            <p class="hint">A main branch, the base currency and default settings are created automatically, and you become its owner.</p>
        </form>
        <template #footer>
            <BaseButton variant="secondary" @click="show = false">Cancel</BaseButton>
            <BaseButton type="submit" form="biz-form" :loading="form.processing">Create business</BaseButton>
        </template>
    </BaseModal>
</template>
