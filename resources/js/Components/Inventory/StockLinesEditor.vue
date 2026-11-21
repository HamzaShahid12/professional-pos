<script setup>
import ProductPicker from './ProductPicker.vue';
import AppIcon from '../Common/AppIcon.vue';

/**
 * Editable list of stock lines. Each line: { product, variant_id, quantity, unit_cost, batch_number, expiry_date }.
 * `withCost` shows the unit cost field (stock increases); `withBatch` shows batch/expiry for expiry-tracked products.
 */
const lines = defineModel({ type: Array, required: true });
const props = defineProps({ errors: { type: Object, default: () => ({}) }, withCost: Boolean, withBatch: Boolean, withDiscount: Boolean });

function add(product) {
    const existing = !product.has_variants && lines.value.find((l) => l.product.id === product.id);
    if (existing) return; // one line per product; edit its quantity instead
    lines.value.push({
        product,
        variant_id: product.has_variants ? '' : null,
        quantity: '',
        unit_cost: props.withCost ? (product.purchase_price ?? '') : '',
        discount: '',
        batch_number: '',
        expiry_date: '',
    });
}

const remove = (i) => lines.value.splice(i, 1);
const err = (i, f) => props.errors[`items.${i}.${f}`];
</script>

<template>
    <div>
        <ProductPicker @select="add" />
        <p v-if="errors.items" class="error-text" style="margin: 8px 0 0">{{ errors.items }}</p>
        <div v-if="lines.length" class="table-wrap" style="margin-top: 12px">
            <table class="table">
                <thead>
                    <tr>
                        <th>Product</th><th style="width: 130px">Quantity</th>
                        <th v-if="withCost" style="width: 140px">Unit cost</th>
                        <th v-if="withDiscount" style="width: 120px">Discount</th>
                        <th v-if="withBatch" style="width: 150px">Batch no.</th>
                        <th v-if="withBatch" style="width: 160px">Expiry</th>
                        <th style="width: 40px" />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(l, i) in lines" :key="`${l.product.id}-${i}`">
                        <td>
                            <strong>{{ l.product.name }}</strong>
                            <div class="muted mono">{{ l.product.sku }}</div>
                            <select v-if="l.product.has_variants" v-model="l.variant_id" class="select" style="margin-top: 6px" :aria-label="`Variant of ${l.product.name}`">
                                <option value="">Choose variant…</option>
                                <option v-for="v in l.product.variants" :key="v.id" :value="v.id">{{ v.name }}</option>
                            </select>
                            <span v-if="err(i, 'variant_id') || err(i, 'product_id')" class="error-text">{{ err(i, 'variant_id') || err(i, 'product_id') }}</span>
                        </td>
                        <td>
                            <input v-model="l.quantity" class="input" type="number" min="0" :step="l.product.allow_decimal ? '0.0001' : '1'" :aria-label="`Quantity of ${l.product.name}`">
                            <span class="muted">{{ l.product.unit }}</span>
                            <span v-if="err(i, 'quantity')" class="error-text" style="display: block">{{ err(i, 'quantity') }}</span>
                        </td>
                        <td v-if="withCost">
                            <input v-model="l.unit_cost" class="input" type="number" min="0" step="0.0001" :aria-label="`Unit cost of ${l.product.name}`">
                            <span v-if="err(i, 'unit_cost')" class="error-text">{{ err(i, 'unit_cost') }}</span>
                        </td>
                        <td v-if="withDiscount">
                            <input v-model="l.discount" class="input" type="number" min="0" step="0.01" :aria-label="`Discount on ${l.product.name}`">
                            <span v-if="err(i, 'discount')" class="error-text">{{ err(i, 'discount') }}</span>
                        </td>
                        <td v-if="withBatch">
                            <input v-if="l.product.track_expiry" v-model="l.batch_number" class="input" :aria-label="`Batch of ${l.product.name}`">
                            <span v-else class="muted">—</span>
                            <span v-if="err(i, 'batch_number')" class="error-text">{{ err(i, 'batch_number') }}</span>
                        </td>
                        <td v-if="withBatch">
                            <input v-if="l.product.track_expiry" v-model="l.expiry_date" class="input" type="date" :aria-label="`Expiry of ${l.product.name}`">
                            <span v-else class="muted">—</span>
                            <span v-if="err(i, 'expiry_date')" class="error-text">{{ err(i, 'expiry_date') }}</span>
                        </td>
                        <td><button type="button" class="btn btn-ghost btn-sm" :aria-label="`Remove ${l.product.name}`" @click="remove(i)"><AppIcon name="x" :size="16" /></button></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
