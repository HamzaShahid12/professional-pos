<script setup>
import axios from 'axios';
import { Head, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import BaseButton from '../../Components/Common/BaseButton.vue';
import BaseModal from '../../Components/Common/BaseModal.vue';
import MoneyDisplay from '../../Components/Common/MoneyDisplay.vue';
import AppIcon from '../../Components/Common/AppIcon.vue';
import { useUiStore } from '../../Stores/ui';
import { formatQty, humanize } from '../../Utils/format';
import { priceCart } from '../../Utils/pricing';

const props = defineProps({
    warehouses: Array, defaultWarehouse: Number, methods: Array, currency: Object, canDiscount: Boolean, canOverridePrice: Boolean,
    canPickCustomer: Boolean, resumed: Object,
});
const ui = useUiStore();

// ---- cart ---------------------------------------------------------------------------------------
const warehouseId = ref(props.resumed?.warehouse_id ?? props.defaultWarehouse);
const customer = ref(props.resumed?.customer ?? null);
const heldId = ref(props.resumed?.id ?? null);
const cart = ref((props.resumed?.items ?? []).map((i) => ({ ...i, key: `${i.product_id}-${i.variant_id}`, allow_decimal: true })));
const serverQuote = ref(null);
const errors = ref([]);

const items = computed(() => cart.value.map((l) => ({
    product_id: l.product_id, variant_id: l.variant_id || null, quantity: l.quantity,
    unit_price: props.canOverridePrice ? l.unit_price : null, discount: props.canDiscount ? l.discount || null : null,
})));

// Totals appear instantly from the browser's own exact calculation. The server re-checks in the background (prices,
// permissions, minimum price, sold-in-whole-units) and again when you press Pay, and its figures always win.
const localQuote = computed(() => (cart.value.length ? priceCart(cart.value.map((l) => ({
    quantity: l.quantity, unit_price: props.canOverridePrice ? l.unit_price : l.list_price ?? l.unit_price, discount: props.canDiscount ? l.discount : '', taxes: l.taxes,
}))) : null));
const quote = computed(() => (cart.value.length === 0 ? null : (localQuote.value ?? serverQuote.value)));
const lineQuote = (i) => quote.value?.lines?.[i];
const total = computed(() => quote.value?.total ?? '0.0000');

let quoteTimer = null;
let quoteSeq = 0;
async function fetchQuote() {
    const mine = ++quoteSeq;
    try {
        const { data } = await axios.post('/pos/quote', { warehouse_id: warehouseId.value, items: items.value });
        if (mine === quoteSeq) { serverQuote.value = data; errors.value = []; }
        return data;
    } catch (e) {
        if (mine === quoteSeq) { serverQuote.value = null; errors.value = messages(e); }
        return null;
    }
}
function requote() {
    clearTimeout(quoteTimer);
    if (cart.value.length === 0) { serverQuote.value = null; errors.value = []; return; }
    // Needed straight away only when the browser cannot price the cart itself (e.g. a resumed held sale).
    quoteTimer = setTimeout(fetchQuote, localQuote.value ? 700 : 150);
}
watch(cart, requote, { deep: true });
watch(warehouseId, () => { search(); });
if (cart.value.length) requote();

function messages(e) {
    const errs = e.response?.data?.errors;
    if (errs) return Object.values(errs).flat();
    return [e.response?.data?.message ?? 'Something went wrong. Please try again.'];
}

function addProduct(p, v = null) {
    const key = `${p.id}-${v?.id ?? ''}`;
    const existing = cart.value.find((l) => l.key === key);
    if (existing) {
        existing.quantity = String(Number(existing.quantity) + 1);
        return;
    }
    cart.value.push({
        key, product_id: p.id, variant_id: v?.id ?? null, name: v ? `${p.name} · ${v.name}` : p.name, sku: v?.sku || p.sku,
        quantity: '1', unit_price: v?.selling_price ?? p.selling_price, list_price: v?.selling_price ?? p.selling_price, discount: '', allow_decimal: p.allow_decimal, unit: p.unit,
        taxes: p.taxes ?? [], image_url: p.image_url ?? null,
    });
}
const removeLine = (i) => cart.value.splice(i, 1);
function clearCart() { cart.value = []; customer.value = null; heldId.value = null; payments.splice(0); }

// ---- product search -----------------------------------------------------------------------------
const term = ref('');
const results = ref([]);
const searching = ref(false);
let searchTimer = null;
let searchSeq = 0;
function search() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(async () => {
        const mine = ++searchSeq;
        searching.value = true;
        try {
            const { data } = await axios.get('/products/lookup', { params: { q: term.value, warehouse_id: warehouseId.value }, headers: { Accept: 'application/json' } });
            if (mine === searchSeq) results.value = data.data;
        } catch { if (mine === searchSeq) results.value = []; } finally { if (mine === searchSeq) searching.value = false; }
    }, 120);
}
function onEnter() {
    // a barcode scanner types the code and presses Enter: add the exact match straight away
    const exact = results.value.find((p) => p.barcode === term.value.trim() && !p.has_variants);
    const hit = exact ?? (results.value.length === 1 && !results.value[0].has_variants ? results.value[0] : null);
    if (hit) { addProduct(hit); term.value = ''; search(); return; }
    const variantHit = results.value.flatMap((p) => p.variants.map((v) => [p, v])).find(([, v]) => v.barcode === term.value.trim());
    if (variantHit) { addProduct(variantHit[0], variantHit[1]); term.value = ''; search(); }
}
search();

// ---- customer -----------------------------------------------------------------------------------
const customerTerm = ref('');
const customerResults = ref([]);
let customerTimer = null;
function searchCustomers() {
    clearTimeout(customerTimer);
    if (!customerTerm.value.trim()) { customerResults.value = []; return; }
    customerTimer = setTimeout(async () => {
        try { customerResults.value = (await axios.get('/customers/lookup', { params: { q: customerTerm.value }, headers: { Accept: 'application/json' } })).data.data; } catch { customerResults.value = []; }
    }, 250);
}
function pickCustomer(c) { customer.value = c; customerTerm.value = ''; customerResults.value = []; }

// ---- hold / resume ------------------------------------------------------------------------------
const showHeld = ref(false);
const held = ref([]);
async function openHeld() {
    showHeld.value = true;
    held.value = (await axios.get('/pos/held', { headers: { Accept: 'application/json' } })).data.data;
}
async function holdSale() {
    try {
        const { data } = await axios.post('/pos/hold', { warehouse_id: warehouseId.value, customer_id: customer.value?.id ?? null, held_id: heldId.value, items: items.value });
        ui.success(`Sale held as ${data.reference_no}.`);
        clearCart();
    } catch (e) { errors.value = messages(e); }
}
async function discard(h) {
    await axios.delete(`/pos/held/${h.id}`);
    held.value = held.value.filter((x) => x.id !== h.id);
}

// ---- payment ------------------------------------------------------------------------------------
const showPay = ref(false);
const payments = reactive([]);
const processing = ref(false);
const done = ref(null);
const payErrors = ref([]);

const num = (v) => Number(v || 0);
const tendered = computed(() => payments.reduce((s, p) => s + num(p.amount), 0));
const difference = computed(() => Math.round((tendered.value - num(total.value)) * 10000) / 10000);
const dp = computed(() => props.currency?.decimal_places ?? 2);

async function openPay() {
    if (!quote.value) return;
    // Confirm with the server before taking money: its total is the one that is charged.
    clearTimeout(quoteTimer);
    const confirmed = await fetchQuote();
    if (!confirmed) return;
    serverQuote.value = confirmed;
    payments.splice(0, payments.length, { method: 'cash', amount: total.value, reference: '' });
    done.value = null; payErrors.value = []; showPay.value = true;
}
const addPayment = () => payments.push({ method: 'card', amount: difference.value < 0 ? String(Math.abs(difference.value)) : '', reference: '' });

async function completeSale() {
    processing.value = true; payErrors.value = [];
    try {
        const { data } = await axios.post('/pos/complete', {
            warehouse_id: warehouseId.value, customer_id: customer.value?.id ?? null, held_id: heldId.value, items: items.value,
            payments: payments.filter((p) => num(p.amount) > 0).map((p) => ({ method: p.method, amount: p.amount, reference: p.reference || null })),
        });
        done.value = data;
        cart.value = []; serverQuote.value = null;
    } catch (e) { payErrors.value = messages(e); } finally { processing.value = false; }
}
function newSale() { showPay.value = false; done.value = null; clearCart(); search(); }

onBeforeUnmount(() => { clearTimeout(quoteTimer); clearTimeout(searchTimer); clearTimeout(customerTimer); });
const creditAvailable = computed(() => customer.value ? Math.max(0, num(customer.value.credit_limit) - num(customer.value.balance)) : 0);
</script>

<template>
    <Head title="Point of sale" />
    <div class="pos">
        <section class="pos-catalog">
            <div class="row" style="margin-bottom: 12px; gap: 8px">
                <input v-model="term" class="input" style="flex: 1" placeholder="Scan barcode or search by name / SKU…" aria-label="Search products" autofocus @input="search" @keydown.enter.prevent="onEnter">
                <select v-if="warehouses.length > 1" v-model="warehouseId" class="select" style="width: 190px" aria-label="Warehouse">
                    <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
                </select>
            </div>
            <div class="pos-grid">
                <div v-for="p in results" :key="p.id" class="card pos-product">
                    <button v-if="!p.has_variants" type="button" class="pos-tile" @click="addProduct(p)">
                        <span class="pos-thumb"><img v-if="p.image_url" :src="p.image_url" :alt="p.name" loading="lazy"><span v-else>{{ p.name.slice(0, 1).toUpperCase() }}</span></span>
                        <strong>{{ p.name }}</strong>
                        <span class="muted mono">{{ p.sku }}</span>
                        <span><MoneyDisplay :amount="p.selling_price" :currency="currency" /></span>
                        <span v-if="p.track_inventory" :class="Number(p.on_hand) <= 0 ? 'error-text' : 'muted'">{{ formatQty(p.on_hand) }} {{ p.unit }} in stock</span>
                    </button>
                    <div v-else class="pos-tile">
                        <span class="pos-thumb"><img v-if="p.image_url" :src="p.image_url" :alt="p.name" loading="lazy"><span v-else>{{ p.name.slice(0, 1).toUpperCase() }}</span></span>
                        <strong>{{ p.name }}</strong>
                        <button v-for="v in p.variants" :key="v.id" type="button" class="btn btn-secondary btn-sm" style="justify-content: space-between" @click="addProduct(p, v)">
                            <span>{{ v.name }}</span><span><MoneyDisplay :amount="v.selling_price ?? p.selling_price" :currency="currency" /> · {{ formatQty(v.on_hand) }}</span>
                        </button>
                    </div>
                </div>
                <p v-if="!results.length && !searching" class="muted">No products found.</p>
            </div>
        </section>

        <aside class="card pos-cart">
            <div class="pos-cart-head">
                <strong>Current sale</strong>
                <span class="row" style="gap: 6px">
                    <button type="button" class="btn btn-ghost btn-sm" @click="openHeld">Held sales</button>
                    <button type="button" class="btn btn-ghost btn-sm" :disabled="!cart.length" @click="clearCart">Clear</button>
                </span>
            </div>

            <div v-if="canPickCustomer" style="padding: 0 14px 8px">
                <div v-if="customer" class="row" style="justify-content: space-between">
                    <span><strong>{{ customer.name }}</strong>
                        <span class="muted"> · owes <MoneyDisplay :amount="customer.balance" :currency="currency" /> · credit left <MoneyDisplay :amount="String(creditAvailable)" :currency="currency" /></span></span>
                    <button type="button" class="btn btn-ghost btn-sm" @click="customer = null">Remove</button>
                </div>
                <div v-else style="position: relative">
                    <input v-model="customerTerm" class="input" placeholder="Walk-in customer (type to choose a customer)" aria-label="Customer" @input="searchCustomers">
                    <div v-if="customerResults.length" class="card" style="position: absolute; z-index: 20; left: 0; right: 0; top: 100%; margin-top: 4px">
                        <button v-for="c in customerResults" :key="c.id" type="button" style="display: block; width: 100%; text-align: left; padding: 8px 14px; background: none; border: 0; cursor: pointer; color: inherit" @click="pickCustomer(c)">
                            <strong>{{ c.name }}</strong> <span class="muted">{{ c.phone }}</span>
                        </button>
                    </div>
                </div>
            </div>

            <div class="pos-lines">
                <p v-if="!cart.length" class="muted" style="padding: 20px; text-align: center">Scan or tap a product to start.</p>
                <div v-for="(l, i) in cart" :key="l.key" class="pos-line">
                    <div style="flex: 1; min-width: 0">
                        <strong>{{ l.name }}</strong>
                        <div class="row" style="gap: 6px; margin-top: 4px; flex-wrap: wrap">
                            <input v-model="l.quantity" class="input" type="number" min="0" :step="l.allow_decimal ? 'any' : '1'" style="width: 80px" :aria-label="`Quantity of ${l.name}`">
                            <span class="muted">×</span>
                            <input v-if="canOverridePrice" v-model="l.unit_price" class="input" type="number" min="0" step="any" style="width: 100px" :aria-label="`Price of ${l.name}`">
                            <MoneyDisplay v-else :amount="l.unit_price" :currency="currency" />
                            <input v-if="canDiscount" v-model="l.discount" class="input" type="number" min="0" step="any" placeholder="Disc." style="width: 80px" :aria-label="`Discount on ${l.name}`">
                        </div>
                    </div>
                    <div style="text-align: right"><strong><MoneyDisplay :amount="lineQuote(i)?.line_total ?? '0'" :currency="currency" /></strong>
                        <div><button type="button" class="btn btn-ghost btn-sm" :aria-label="`Remove ${l.name}`" @click="removeLine(i)"><AppIcon name="x" :size="16" /></button></div></div>
                </div>
            </div>

            <div v-if="errors.length" class="error-text" style="padding: 8px 14px"><div v-for="(m, i) in errors" :key="i">{{ m }}</div></div>

            <div class="pos-totals">
                <div class="row" style="justify-content: space-between"><span class="muted">Subtotal</span><MoneyDisplay :amount="quote?.subtotal ?? '0'" :currency="currency" /></div>
                <div v-if="quote && Number(quote.discount_total) > 0" class="row" style="justify-content: space-between"><span class="muted">Discount</span><MoneyDisplay :amount="quote.discount_total" :currency="currency" /></div>
                <div class="row" style="justify-content: space-between"><span class="muted">Tax</span><MoneyDisplay :amount="quote?.tax_total ?? '0'" :currency="currency" /></div>
                <div class="row" style="justify-content: space-between; font-size: 20px"><strong>Total</strong><strong><MoneyDisplay :amount="total" :currency="currency" /></strong></div>
                <div class="row" style="gap: 8px; margin-top: 8px">
                    <BaseButton variant="secondary" :disabled="!cart.length" @click="holdSale">Hold</BaseButton>
                    <BaseButton style="flex: 1" :disabled="!quote || errors.length > 0" @click="openPay">Pay</BaseButton>
                </div>
            </div>
        </aside>
    </div>

    <BaseModal :show="showPay" :title="done ? 'Sale complete' : 'Take payment'" @close="done ? newSale() : (showPay = false)">
        <template v-if="done">
            <p style="font-size: 18px; margin-top: 0"><strong>{{ done.reference_no }}</strong> · <MoneyDisplay :amount="done.total" :currency="currency" /></p>
            <p v-if="Number(done.change_due) > 0" style="font-size: 22px">Change: <strong><MoneyDisplay :amount="done.change_due" :currency="currency" /></strong></p>
            <p v-if="Number(done.balance_due) > 0">Added to the customer's account: <strong><MoneyDisplay :amount="done.balance_due" :currency="currency" /></strong></p>
        </template>
        <template v-else>
            <p style="margin-top: 0; font-size: 20px">Total <strong><MoneyDisplay :amount="total" :currency="currency" /></strong></p>
            <div v-for="(p, i) in payments" :key="i" class="row" style="gap: 8px; margin-bottom: 8px">
                <select v-model="p.method" class="select" style="width: 120px" :aria-label="`Payment method ${i + 1}`"><option v-for="m in methods" :key="m" :value="m">{{ humanize(m) }}</option></select>
                <input v-model="p.amount" class="input" type="number" min="0" step="any" style="flex: 1" :aria-label="`Payment amount ${i + 1}`">
                <input v-if="p.method !== 'cash'" v-model="p.reference" class="input" placeholder="Reference" style="width: 130px" :aria-label="`Payment reference ${i + 1}`">
                <button type="button" class="btn btn-ghost btn-sm" aria-label="Remove payment" @click="payments.splice(i, 1)"><AppIcon name="x" :size="16" /></button>
            </div>
            <button type="button" class="btn btn-ghost btn-sm" @click="addPayment">+ Split payment</button>
            <p v-if="difference >= 0" style="font-size: 18px">Change <strong>{{ difference.toFixed(dp) }}</strong></p>
            <p v-else style="font-size: 18px">{{ customer ? 'On account' : 'Still to pay' }} <strong>{{ Math.abs(difference).toFixed(dp) }}</strong></p>
            <div v-if="payErrors.length" class="error-text"><div v-for="(m, i) in payErrors" :key="i">{{ m }}</div></div>
        </template>
        <template #footer>
            <template v-if="done">
                <a :href="done.receipt_url" target="_blank" rel="noopener" class="btn btn-secondary">Print receipt</a>
                <BaseButton @click="newSale">New sale</BaseButton>
            </template>
            <template v-else>
                <BaseButton variant="secondary" @click="showPay = false">Back</BaseButton>
                <BaseButton :loading="processing" @click="completeSale">Complete sale</BaseButton>
            </template>
        </template>
    </BaseModal>

    <BaseModal :show="showHeld" title="Held sales" @close="showHeld = false">
        <p v-if="!held.length" class="muted">No sales on hold.</p>
        <div v-for="h in held" :key="h.id" class="row" style="justify-content: space-between; padding: 8px 0; border-bottom: 1px solid var(--border, #e5e7eb)">
            <span><strong>{{ h.reference_no }}</strong> <span class="muted">{{ h.items_count }} items · {{ h.customer ?? 'Walk-in' }}</span></span>
            <span class="row" style="gap: 6px">
                <button type="button" class="btn btn-secondary btn-sm" @click="router.visit(`/pos?resume=${h.id}`)">Resume</button>
                <button type="button" class="btn btn-ghost btn-sm" @click="discard(h)">Discard</button>
            </span>
        </div>
        <template #footer><BaseButton variant="secondary" @click="showHeld = false">Close</BaseButton></template>
    </BaseModal>
</template>

<style scoped>
.pos { display: grid; grid-template-columns: minmax(0, 1fr) 420px; gap: 16px; align-items: start; }
.pos-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 10px; }
.pos-product { padding: 0; overflow: hidden; }
.pos-thumb { width: 100%; aspect-ratio: 4 / 3; border-radius: 10px; background: var(--primary-50); color: var(--primary); display: grid; place-items: center; font-size: 28px; font-weight: 700; overflow: hidden; }
.pos-thumb img { width: 100%; height: 100%; object-fit: cover; }
.pos-tile { display: flex; flex-direction: column; gap: 4px; width: 100%; padding: 12px; text-align: left; background: none; border: 0; color: inherit; cursor: pointer; }
.pos-cart { position: sticky; top: 76px; padding: 0; display: flex; flex-direction: column; max-height: calc(100vh - 100px); }
.pos-cart-head { display: flex; justify-content: space-between; align-items: center; padding: 12px 14px; }
.pos-lines { overflow: auto; flex: 1; min-height: 120px; }
.pos-line { display: flex; gap: 10px; padding: 10px 14px; border-top: 1px solid var(--border, #e5e7eb); }
.pos-totals { padding: 12px 14px; border-top: 1px solid var(--border, #e5e7eb); display: grid; gap: 4px; }
@media (max-width: 900px) { .pos { grid-template-columns: 1fr; } .pos-cart { position: static; max-height: none; } }
</style>
