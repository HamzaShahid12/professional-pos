<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import PageHeader from '../../Components/Common/PageHeader.vue';
import BaseCard from '../../Components/Common/BaseCard.vue';
import BaseInput from '../../Components/Common/BaseInput.vue';
import BaseSelect from '../../Components/Common/BaseSelect.vue';
import BaseButton from '../../Components/Common/BaseButton.vue';
import CurrencyInput from '../../Components/Common/CurrencyInput.vue';
import { useMoney } from '../../Composables/useMoney';
import { trimRate } from '../../Utils/format';

const props = defineProps({ product: Object, categories: Array, brands: Array, units: Array, taxes: Array });
const { base } = useMoney();
const editing = computed(() => !!props.product);
const p = props.product;
const dec = (v) => (v === null || v === undefined ? '' : String(v));

const form = useForm({
    _method: editing.value ? 'put' : 'post',
    name: p?.name ?? '', sku: p?.sku ?? '', barcode: p?.barcode ?? '', description: p?.description ?? '',
    category_id: p?.category_id ?? '', brand_id: p?.brand_id ?? '', unit_id: p?.unit_id ?? props.units.find((u) => u.short_name === 'pcs')?.id ?? '',
    purchase_price: dec(p?.purchase_price), selling_price: dec(p?.selling_price), wholesale_price: dec(p?.wholesale_price), min_selling_price: dec(p?.min_selling_price),
    min_stock: dec(p?.min_stock) || '0',
    sync_relations: true,
    tax_ids: [...(p?.tax_ids ?? [])],
    is_active: p?.is_active ?? true, track_inventory: p?.track_inventory ?? true, track_expiry: p?.track_expiry ?? false,
    variants: (p?.variants ?? []).map((v) => ({ id: v.id, name: v.name, sku: v.sku, barcode: v.barcode ?? '', purchase_price: dec(v.purchase_price), selling_price: dec(v.selling_price), wholesale_price: dec(v.wholesale_price), is_active: v.is_active })),
    images: [],
    remove_image_ids: [],
});

const addVariant = () => form.variants.push({ id: null, name: '', sku: '', barcode: '', purchase_price: '', selling_price: '', wholesale_price: '', is_active: true });
const removeVariant = (i) => form.variants.splice(i, 1);
const toggleImage = (id) => {
    form.remove_image_ids = form.remove_image_ids.includes(id) ? form.remove_image_ids.filter((x) => x !== id) : [...form.remove_image_ids, id];
};
const err = (k) => form.errors[k];

function submit() {
    // POST with method spoofing: multipart uploads cannot be sent with a real PUT.
    form.post(editing.value ? `/products/${p.id}` : '/products', { forceFormData: true, preserveScroll: true });
}
const symbol = computed(() => base.value?.symbol ?? '');
</script>

<template>
    <Head :title="editing ? 'Edit product' : 'New product'" />
    <PageHeader :title="editing ? `Edit ${p.name}` : 'New product'" crumb="Products">
        <Link href="/products" class="btn btn-secondary">Cancel</Link>
    </PageHeader>

    <form @submit.prevent="submit">
        <div class="grid grid-2">
            <BaseCard title="Basic information">
                <BaseInput v-model="form.name" label="Product name" :error="err('name')" required />
                <div class="form-grid">
                    <BaseInput v-model="form.sku" label="SKU" :error="err('sku')" required />
                    <BaseInput v-model="form.barcode" label="Barcode" :error="err('barcode')" hint="Scan or type; must be unique." />
                    <BaseSelect v-model="form.category_id" label="Category" placeholder="None" :options="categories.map((c) => ({ value: c.id, label: c.name }))" :error="err('category_id')" />
                    <BaseSelect v-model="form.brand_id" label="Brand" placeholder="None" :options="brands.map((b) => ({ value: b.id, label: b.name }))" :error="err('brand_id')" />
                    <BaseSelect v-model="form.unit_id" label="Unit" :options="units.map((u) => ({ value: u.id, label: `${u.name} (${u.short_name})` }))" :error="err('unit_id')" />
                    <BaseInput v-model="form.min_stock" label="Minimum stock" type="number" :error="err('min_stock')" hint="Low-stock alerts use this." />
                </div>
                <div class="field">
                    <label>Description</label>
                    <textarea v-model="form.description" class="textarea" rows="3" />
                </div>
            </BaseCard>

            <BaseCard :title="`Pricing (${base?.code ?? ''})`">
                <div class="form-grid">
                    <CurrencyInput v-model="form.purchase_price" label="Purchase price" :symbol="symbol" :decimals="4" :error="err('purchase_price')" required />
                    <CurrencyInput v-model="form.selling_price" label="Selling price" :symbol="symbol" :decimals="4" :error="err('selling_price')" required />
                    <CurrencyInput v-model="form.wholesale_price" label="Wholesale price" :symbol="symbol" :decimals="4" :error="err('wholesale_price')" />
                    <CurrencyInput v-model="form.min_selling_price" label="Minimum selling price" :symbol="symbol" :decimals="4" :error="err('min_selling_price')" />
                </div>
                <div class="field">
                    <label>Taxes</label>
                    <div v-if="taxes.length" class="perm-list">
                        <label v-for="t in taxes" :key="t.id"><input v-model="form.tax_ids" type="checkbox" :value="t.id"> {{ t.name }} ({{ trimRate(t.rate) }}%{{ t.is_inclusive ? ', inclusive' : '' }})</label>
                    </div>
                    <span v-else class="hint">No taxes defined yet. Add them under Tax Settings.</span>
                    <span v-if="err('tax_ids.0')" class="error-text">{{ err('tax_ids.0') }}</span>
                </div>
                <label class="check"><input v-model="form.is_active" type="checkbox"> Active (can be sold)</label>
                <label class="check"><input v-model="form.track_inventory" type="checkbox"> Track inventory</label>
                <label class="check"><input v-model="form.track_expiry" type="checkbox"> Track batch / expiry dates</label>
            </BaseCard>
        </div>

        <BaseCard title="Variants" style="margin-top: 16px">
            <template #header><BaseButton type="button" size="sm" variant="secondary" @click="addVariant">Add variant</BaseButton></template>
            <p v-if="!form.variants.length" class="muted" style="margin: 0">No variants. Add some for sizes, colours or packs. Leave a variant price blank to use the product price.</p>
            <div v-for="(v, i) in form.variants" :key="v.id ?? `new-${i}`" class="card" style="padding: 14px; margin-bottom: 12px">
                <div class="form-grid">
                    <BaseInput v-model="v.name" label="Variant name" :error="err(`variants.${i}.name`)" required />
                    <BaseInput v-model="v.sku" label="SKU" :error="err(`variants.${i}.sku`)" required />
                    <BaseInput v-model="v.barcode" label="Barcode" :error="err(`variants.${i}.barcode`)" />
                    <CurrencyInput v-model="v.purchase_price" label="Purchase price" :decimals="4" :error="err(`variants.${i}.purchase_price`)" />
                    <CurrencyInput v-model="v.selling_price" label="Selling price" :decimals="4" :error="err(`variants.${i}.selling_price`)" />
                    <CurrencyInput v-model="v.wholesale_price" label="Wholesale price" :decimals="4" :error="err(`variants.${i}.wholesale_price`)" />
                </div>
                <div class="row" style="justify-content: space-between">
                    <label class="check" style="margin: 0"><input v-model="v.is_active" type="checkbox"> Active</label>
                    <BaseButton type="button" size="sm" variant="ghost" @click="removeVariant(i)">Remove</BaseButton>
                </div>
                <span v-if="err(`variants.${i}.id`)" class="error-text">{{ err(`variants.${i}.id`) }}</span>
            </div>
        </BaseCard>

        <BaseCard title="Images" style="margin-top: 16px">
            <div v-if="product?.images?.length" class="row" style="margin-bottom: 12px">
                <div v-for="img in product.images" :key="img.id" style="text-align: center">
                    <img :src="img.url" alt="" style="height: 84px; width: 84px; object-fit: cover; border-radius: 10px; border: 1px solid var(--border)" :style="form.remove_image_ids.includes(img.id) ? 'opacity: .3' : ''">
                    <div><button type="button" class="btn btn-ghost btn-sm" @click="toggleImage(img.id)">{{ form.remove_image_ids.includes(img.id) ? 'Keep' : 'Remove' }}</button></div>
                </div>
            </div>
            <input type="file" multiple accept="image/png,image/jpeg,image/webp" @change="form.images = [...$event.target.files]">
            <div class="hint">Up to 5 images (PNG, JPG, WebP), 2 MB each. The first image is the main one.</div>
            <span v-if="err('images') || err('images.0')" class="error-text">{{ err('images') || err('images.0') }}</span>
        </BaseCard>

        <div class="row" style="margin-top: 16px">
            <BaseButton type="submit" :loading="form.processing">{{ editing ? 'Save changes' : 'Create product' }}</BaseButton>
            <Link href="/products" class="btn btn-secondary">Cancel</Link>
        </div>
    </form>
</template>