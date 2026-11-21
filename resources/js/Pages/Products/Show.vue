<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import PageHeader from '../../Components/Common/PageHeader.vue';
import BaseCard from '../../Components/Common/BaseCard.vue';
import BaseBadge from '../../Components/Common/BaseBadge.vue';
import BaseButton from '../../Components/Common/BaseButton.vue';
import MoneyDisplay from '../../Components/Common/MoneyDisplay.vue';
import { useConfirm } from '../../Composables/useConfirm';
import { formatDateTime, trimRate } from '../../Utils/format';

const props = defineProps({ product: Object, prices: Array, can: Object });
const { confirm } = useConfirm();
const tz = usePage().props.tenant?.business.timezone;

const labels = { purchase_price: 'Purchase price', selling_price: 'Selling price', wholesale_price: 'Wholesale price', min_selling_price: 'Minimum selling price' };

async function archive() {
    if (await confirm({ title: 'Archive product', message: `Archive "${props.product.name}"? Past sales and stock history are kept.`, confirmText: 'Archive', danger: true })) {
        router.delete(`/products/${props.product.id}`);
    }
}
</script>

<template>
    <Head :title="product.name" />
    <PageHeader :title="product.name" crumb="Products">
        <Link href="/products" class="btn btn-secondary">Back</Link>
        <Link v-if="can.update" :href="`/products/${product.id}/edit`" class="btn">Edit</Link>
        <BaseButton v-if="can.delete" variant="danger" @click="archive">Archive</BaseButton>
    </PageHeader>

    <div class="grid grid-2">
        <BaseCard title="Details">
            <div class="row" style="align-items: flex-start; flex-wrap: nowrap">
                <img v-if="product.images.length" :src="product.images[0].url" alt="" style="width: 120px; height: 120px; object-fit: cover; border-radius: 12px">
                <table class="table" style="flex: 1">
                    <tbody>
                        <tr><td class="muted">SKU</td><td class="mono">{{ product.sku }}</td></tr>
                        <tr><td class="muted">Barcode</td><td class="mono">{{ product.barcode ?? '—' }}</td></tr>
                        <tr><td class="muted">Category</td><td>{{ product.category ?? '—' }}</td></tr>
                        <tr><td class="muted">Brand</td><td>{{ product.brand ?? '—' }}</td></tr>
                        <tr><td class="muted">Unit</td><td>{{ product.unit ?? '—' }}</td></tr>
                        <tr><td class="muted">Minimum stock</td><td class="mono">{{ trimRate(product.min_stock) }}</td></tr>
                        <tr><td class="muted">Status</td><td><BaseBadge :variant="product.is_active ? 'success' : 'neutral'">{{ product.is_active ? 'Active' : 'Inactive' }}</BaseBadge></td></tr>
                    </tbody>
                </table>
            </div>
            <p v-if="product.description" class="muted">{{ product.description }}</p>
        </BaseCard>

        <BaseCard title="Pricing & tax">
            <table class="table">
                <tbody>
                    <tr><td class="muted">Purchase price</td><td class="text-right"><MoneyDisplay :amount="product.purchase_price" /></td></tr>
                    <tr><td class="muted">Selling price</td><td class="text-right"><strong><MoneyDisplay :amount="product.selling_price" /></strong></td></tr>
                    <tr><td class="muted">Wholesale price</td><td class="text-right"><MoneyDisplay v-if="product.wholesale_price !== null" :amount="product.wholesale_price" /><span v-else class="muted">—</span></td></tr>
                    <tr><td class="muted">Minimum selling price</td><td class="text-right"><MoneyDisplay v-if="product.min_selling_price !== null" :amount="product.min_selling_price" /><span v-else class="muted">—</span></td></tr>
                    <tr><td class="muted">Taxes</td><td class="text-right"><span v-if="!product.taxes.length" class="muted">None</span><BaseBadge v-for="t in product.taxes" :key="t.id" variant="info" style="margin-left: 4px">{{ t.name }} {{ trimRate(t.rate) }}%</BaseBadge></td></tr>
                </tbody>
            </table>
        </BaseCard>
    </div>

    <BaseCard v-if="product.variants.length" title="Variants" flush style="margin-top: 16px">
        <table class="table">
            <thead><tr><th>Variant</th><th>SKU</th><th>Barcode</th><th class="text-right">Cost</th><th class="text-right">Price</th><th>Status</th></tr></thead>
            <tbody>
                <tr v-for="v in product.variants" :key="v.id">
                    <td>{{ v.name }}</td><td class="mono">{{ v.sku }}</td><td class="mono">{{ v.barcode ?? '—' }}</td>
                    <td class="text-right"><MoneyDisplay v-if="v.purchase_price !== null" :amount="v.purchase_price" /><span v-else class="muted">same</span></td>
                    <td class="text-right"><MoneyDisplay v-if="v.selling_price !== null" :amount="v.selling_price" /><span v-else class="muted">same</span></td>
                    <td><BaseBadge :variant="v.is_active ? 'success' : 'neutral'">{{ v.is_active ? 'Active' : 'Inactive' }}</BaseBadge></td>
                </tr>
            </tbody>
        </table>
    </BaseCard>

    <BaseCard title="Price history" flush style="margin-top: 16px">
        <table class="table">
            <thead><tr><th>When</th><th>Price</th><th class="text-right">From</th><th class="text-right">To</th><th>By</th><th>Reason</th></tr></thead>
            <tbody>
                <tr v-for="h in prices" :key="h.id">
                    <td>{{ formatDateTime(h.at, tz) }}</td>
                    <td>{{ labels[h.type] ?? h.type }}<span v-if="h.variant" class="muted"> · {{ h.variant }}</span></td>
                    <td class="text-right"><MoneyDisplay v-if="h.old !== null" :amount="h.old" /><span v-else class="muted">—</span></td>
                    <td class="text-right"><MoneyDisplay v-if="h.new !== null" :amount="h.new" /><span v-else class="muted">cleared</span></td>
                    <td class="muted">{{ h.by ?? 'System' }}</td><td class="muted">{{ h.reason ?? '' }}</td>
                </tr>
            </tbody>
        </table>
    </BaseCard>
</template>
