<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import PageHeader from '../../Components/Common/PageHeader.vue';
import BaseCard from '../../Components/Common/BaseCard.vue';
import BaseInput from '../../Components/Common/BaseInput.vue';
import BaseSelect from '../../Components/Common/BaseSelect.vue';
import BaseButton from '../../Components/Common/BaseButton.vue';

const props = defineProps({ business: Object, settings: Object, timezones: Array });
const s = props.settings;

const form = useForm({
    name: props.business.name,
    legal_name: props.business.legal_name ?? '',
    tax_number: props.business.tax_number ?? '',
    email: props.business.email ?? '',
    phone: props.business.phone ?? '',
    address: props.business.address ?? '',
    country: props.business.country ?? '',
    timezone: props.business.timezone,
    logo: null,
    settings: {
        inventory: { allow_negative_stock: !!s['inventory.allow_negative_stock'], costing_method: s['inventory.costing_method'] },
        sales: {
            invoice_prefix: s['sales.invoice_prefix'], receipt_width: String(s['sales.receipt_width']),
            require_open_register: !!s['sales.require_open_register'],
            invoice_footer: s['sales.invoice_footer'] ?? '', invoice_terms: s['sales.invoice_terms'] ?? '',
        },
    },
});
const err = (key) => form.errors[key];
const submit = () => form.post('/settings/business', { forceFormData: true, preserveScroll: true });
</script>

<template>
    <Head title="Business settings" />
    <PageHeader title="Business settings" crumb="Settings" />

    <form @submit.prevent="submit">
        <BaseCard title="Business profile" style="margin-bottom: 16px">
            <div class="form-grid">
                <BaseInput v-model="form.name" label="Business name" :error="err('name')" required />
                <BaseInput v-model="form.legal_name" label="Legal name" :error="err('legal_name')" />
                <BaseInput v-model="form.email" label="Email" type="email" :error="err('email')" />
                <BaseInput v-model="form.phone" label="Phone" :error="err('phone')" />
                <BaseInput v-model="form.country" label="Country" :error="err('country')" />
                <BaseInput v-model="form.tax_number" label="Tax / NTN number" :error="err('tax_number')" />
                <BaseSelect v-model="form.timezone" label="Timezone" :options="timezones" :error="err('timezone')" />
                <BaseInput :model-value="business.base_currency_code" label="Base currency" disabled hint="Fixed when the business is created." />
            </div>
            <BaseInput v-model="form.address" label="Address" :error="err('address')" />
            <div class="field">
                <label>Logo</label>
                <div class="row">
                    <img v-if="business.logo_url" :src="business.logo_url" alt="Logo" style="height: 44px; border-radius: 8px">
                    <input type="file" accept="image/png,image/jpeg,image/webp" @change="form.logo = $event.target.files[0]">
                </div>
                <span v-if="err('logo')" class="error-text">{{ err('logo') }}</span>
            </div>
        </BaseCard>

        <div class="grid grid-2">
            <BaseCard title="Inventory policy">
                <label class="check"><input v-model="form.settings.inventory.allow_negative_stock" type="checkbox"> Allow selling below zero stock</label>
                <BaseSelect v-model="form.settings.inventory.costing_method" label="Costing method" :options="[{ value: 'average', label: 'Weighted average' }, { value: 'fifo', label: 'FIFO' }]" :error="err('settings.inventory.costing_method')" />
                <p class="hint" style="margin: 0">Applied by inventory and profit reports from Phase 3 onward. Historical profit always uses the cost at the time of sale.</p>
            </BaseCard>

            <BaseCard title="Invoices & receipts">
                <div class="form-grid">
                    <BaseInput v-model="form.settings.sales.invoice_prefix" label="Invoice prefix" :error="err('settings.sales.invoice_prefix')" />
                    <BaseSelect v-model="form.settings.sales.receipt_width" label="Thermal receipt width" :options="[{ value: '80', label: '80 mm' }, { value: '58', label: '58 mm' }]" />
                </div>
                <label class="check"><input v-model="form.settings.sales.require_open_register" type="checkbox"> Cashiers must open a cash register before taking cash</label>
                <BaseInput v-model="form.settings.sales.invoice_footer" label="Footer text" />
                <BaseInput v-model="form.settings.sales.invoice_terms" label="Terms & conditions" />
            </BaseCard>
        </div>

        <div style="margin-top: 16px"><BaseButton type="submit" :loading="form.processing">Save settings</BaseButton></div>
    </form>
</template>
