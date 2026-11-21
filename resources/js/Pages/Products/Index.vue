<script setup>
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import PageHeader from '../../Components/Common/PageHeader.vue';
import BaseCard from '../../Components/Common/BaseCard.vue';
import BaseTable from '../../Components/Common/BaseTable.vue';
import BasePagination from '../../Components/Common/BasePagination.vue';
import BaseModal from '../../Components/Common/BaseModal.vue';
import BaseButton from '../../Components/Common/BaseButton.vue';
import BaseBadge from '../../Components/Common/BaseBadge.vue';
import BaseInput from '../../Components/Common/BaseInput.vue';
import BaseSelect from '../../Components/Common/BaseSelect.vue';
import SearchInput from '../../Components/Common/SearchInput.vue';
import MoneyDisplay from '../../Components/Common/MoneyDisplay.vue';
import { useConfirm } from '../../Composables/useConfirm';

const props = defineProps({ products: Object, filters: Object, categories: Array, brands: Array, can: Object });
const page = usePage();
const { confirm } = useConfirm();

// ---- filters (debounced; state lives in the URL so pages are shareable) ----
const filter = reactive({ search: props.filters.search ?? '', category_id: props.filters.category_id ?? '', brand_id: props.filters.brand_id ?? '', status: props.filters.status ?? '' });
let timer = null;
watch(filter, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        const params = Object.fromEntries(Object.entries(filter).filter(([, v]) => v !== '' && v !== null));
        router.get('/products', params, { preserveState: true, preserveScroll: true, replace: true });
    }, 300);
});

const columns = [
    { key: 'name', label: 'Product' }, { key: 'category', label: 'Category' }, { key: 'brand', label: 'Brand' },
    { key: 'purchase_price', label: 'Cost', align: 'right' }, { key: 'selling_price', label: 'Price', align: 'right' }, { key: 'is_active', label: 'Status' },
];

// ---- import ----
const showImport = ref(false);
const importForm = useForm({ file: null });
const importErrors = computed(() => page.props.flash?.import_errors ?? null);
const submitImport = () => importForm.post('/products/import', {
    forceFormData: true, preserveScroll: true,
    onSuccess: () => { if (!page.props.flash?.import_errors) showImport.value = false; importForm.reset(); },
});

// ---- bulk price update ----
const showBulk = ref(false);
const bulk = useForm({ scope: 'all', category_id: '', brand_id: '', field: 'selling_price', mode: 'percent', value: '', reason: '' });
const submitBulk = () => bulk.post('/products/bulk-prices', { preserveScroll: true, onSuccess: () => { showBulk.value = false; bulk.reset(); } });

async function archive(p) {
    if (await confirm({ title: 'Archive product', message: `Archive "${p.name}"? It disappears from lists and the POS, but past sales and stock history are kept.`, confirmText: 'Archive', danger: true })) {
        router.delete(`/products/${p.id}`, { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Products" />
    <PageHeader title="Products">
        <BaseButton v-if="can.update" variant="secondary" @click="showBulk = true">Bulk price update</BaseButton>
        <BaseButton v-if="can.create" variant="secondary" @click="showImport = true">Import</BaseButton>
        <a class="btn btn-secondary" href="/products/export">Export</a>
        <Link v-if="can.create" href="/products/create" class="btn">Add product</Link>
    </PageHeader>

    <BaseCard flush>
        <div class="toolbar">
            <SearchInput v-model="filter.search" placeholder="Name, SKU or barcode" />
            <select v-model="filter.category_id" class="select" style="width: 170px" aria-label="Category"><option value="">All categories</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select>
            <select v-model="filter.brand_id" class="select" style="width: 160px" aria-label="Brand"><option value="">All brands</option><option v-for="b in brands" :key="b.id" :value="b.id">{{ b.name }}</option></select>
            <select v-model="filter.status" class="select" style="width: 130px" aria-label="Status"><option value="">Any status</option><option value="active">Active</option><option value="inactive">Inactive</option></select>
        </div>
        <BaseTable :columns="columns" :rows="products.data" empty-title="No products found" empty-message="Try a different search, or add your first product.">
            <template #cell-name="{ row }">
                <div class="row" style="flex-wrap: nowrap">
                    <span class="avatar" style="border-radius: 8px"><img v-if="row.image_url" :src="row.image_url" alt=""><template v-else>{{ row.name.slice(0, 1).toUpperCase() }}</template></span>
                    <div>
                        <Link :href="`/products/${row.id}`"><strong>{{ row.name }}</strong></Link>
                        <div class="muted mono">{{ row.sku }}<template v-if="row.barcode"> · {{ row.barcode }}</template> <BaseBadge v-if="row.has_variants" variant="info">Variants</BaseBadge></div>
                    </div>
                </div>
            </template>
            <template #cell-category="{ row }">{{ row.category ?? '—' }}</template>
            <template #cell-brand="{ row }">{{ row.brand ?? '—' }}</template>
            <template #cell-purchase_price="{ row }"><MoneyDisplay :amount="row.purchase_price" /></template>
            <template #cell-selling_price="{ row }"><MoneyDisplay :amount="row.selling_price" /><span class="muted"> / {{ row.unit }}</span></template>
            <template #cell-is_active="{ row }"><BaseBadge :variant="row.is_active ? 'success' : 'neutral'">{{ row.is_active ? 'Active' : 'Inactive' }}</BaseBadge></template>
            <template #actions="{ row }">
                <Link v-if="can.update" :href="`/products/${row.id}/edit`" class="btn btn-secondary btn-sm">Edit</Link>
                <BaseButton v-if="can.delete" size="sm" variant="ghost" @click="archive(row)">Archive</BaseButton>
            </template>
        </BaseTable>
        <BasePagination :paginator="products" />
    </BaseCard>

    <BaseModal :show="showImport" title="Import products from Excel" @close="showImport = false">
        <p style="margin-top: 0">Upload an .xlsx or .csv file. Rows whose <strong>SKU</strong> already exists are updated; others are created. If any row has a problem, nothing is imported.</p>
        <p><a href="/products/import-template">Download the template</a> · or <a href="/products/export">export your catalog</a>, edit it and re-import.</p>
        <form id="import-form" @submit.prevent="submitImport">
            <div class="field">
                <input type="file" accept=".xlsx,.xls,.csv" required @change="importForm.file = $event.target.files[0]">
                <span v-if="importForm.errors.file" class="error-text">{{ importForm.errors.file }}</span>
            </div>
        </form>
        <div v-if="importErrors" class="card" style="padding: 12px 16px; max-height: 220px; overflow: auto; border-color: var(--danger)">
            <strong class="error-text">Fix these rows and upload again:</strong>
            <ul style="margin: 6px 0 0; padding-left: 18px">
                <li v-for="(msgs, row) in importErrors" :key="row"><strong>Row {{ row }}:</strong> {{ msgs.join(' ') }}</li>
            </ul>
        </div>
        <template #footer>
            <BaseButton variant="secondary" @click="showImport = false">Close</BaseButton>
            <BaseButton type="submit" form="import-form" :loading="importForm.processing">Import</BaseButton>
        </template>
    </BaseModal>

    <BaseModal :show="showBulk" title="Bulk price update" @close="showBulk = false">
        <form id="bulk-form" @submit.prevent="submitBulk">
            <div class="form-grid">
                <BaseSelect v-model="bulk.scope" label="Apply to" :options="[{ value: 'all', label: 'All products' }, { value: 'category', label: 'A category' }, { value: 'brand', label: 'A brand' }]" />
                <BaseSelect v-if="bulk.scope === 'category'" v-model="bulk.category_id" label="Category" placeholder="Choose…" :options="categories.map((c) => ({ value: c.id, label: c.name }))" :error="bulk.errors.category_id" />
                <BaseSelect v-if="bulk.scope === 'brand'" v-model="bulk.brand_id" label="Brand" placeholder="Choose…" :options="brands.map((b) => ({ value: b.id, label: b.name }))" :error="bulk.errors.brand_id" />
                <BaseSelect v-model="bulk.field" label="Price" :options="[{ value: 'selling_price', label: 'Selling price' }, { value: 'purchase_price', label: 'Purchase price' }, { value: 'wholesale_price', label: 'Wholesale price' }, { value: 'min_selling_price', label: 'Minimum selling price' }]" />
                <BaseSelect v-model="bulk.mode" label="Change" :options="[{ value: 'percent', label: 'By percent (+/-)' }, { value: 'add', label: 'Add / subtract amount' }, { value: 'set', label: 'Set to exact value' }]" />
                <BaseInput v-model="bulk.value" label="Value" type="number" required :error="bulk.errors.value" hint="Use a negative number to reduce." />
                <BaseInput v-model="bulk.reason" label="Reason (kept in price history)" :error="bulk.errors.reason" />
            </div>
            <p class="hint" style="margin: 0">Every change is recorded in each product's price history. Selling prices are never pushed below a product's minimum selling price; those products are skipped.</p>
        </form>
        <template #footer>
            <BaseButton variant="secondary" @click="showBulk = false">Cancel</BaseButton>
            <BaseButton type="submit" form="bulk-form" :loading="bulk.processing">Apply</BaseButton>
        </template>
    </BaseModal>
</template>
