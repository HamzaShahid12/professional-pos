<script setup>
import axios from 'axios';
import { onBeforeUnmount, ref } from 'vue';

/** Search-as-you-type picker for inventory-tracked products. Emits `select` with the lookup payload. */
const emit = defineEmits(['select']);
defineProps({ placeholder: { type: String, default: 'Scan barcode or search product…' }, disabled: Boolean });

const term = ref('');
const results = ref([]);
const open = ref(false);
const loading = ref(false);
let timer = null;
let seq = 0;

function search() {
    clearTimeout(timer);
    if (term.value.trim().length < 1) { results.value = []; open.value = false; return; }
    timer = setTimeout(async () => {
        const mine = ++seq;
        loading.value = true;
        try {
            const { data } = await axios.get('/products/lookup', { params: { q: term.value, stockable: 1 }, headers: { Accept: 'application/json' } });
            if (mine !== seq) return; // a newer search replaced this one
            results.value = data.data;
            open.value = true;
        } catch {
            results.value = [];
        } finally {
            if (mine === seq) loading.value = false;
        }
    }, 250);
}

function pick(product) {
    emit('select', product);
    term.value = '';
    results.value = [];
    open.value = false;
}

function closeSoon() {
    setTimeout(() => { open.value = false; }, 150);
}

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <div class="picker" style="position: relative">
        <input v-model="term" class="input" :placeholder="placeholder" :disabled="disabled" autocomplete="off" aria-label="Find product"
               @input="search" @focus="open = results.length > 0" @blur="closeSoon" @keydown.enter.prevent="results.length === 1 && pick(results[0])">
        <div v-if="open" class="card" style="position: absolute; z-index: 20; left: 0; right: 0; top: 100%; margin-top: 4px; max-height: 280px; overflow: auto">
            <div v-if="results.length === 0" class="muted" style="padding: 10px 14px">{{ loading ? 'Searching…' : 'No inventory products found.' }}</div>
            <button v-for="p in results" :key="p.id" type="button" class="picker-item" style="display: block; width: 100%; text-align: left; padding: 8px 14px; background: none; border: 0; cursor: pointer; color: inherit" @mousedown.prevent="pick(p)">
                <strong>{{ p.name }}</strong>
                <span class="muted mono"> {{ p.sku }}<template v-if="p.barcode"> · {{ p.barcode }}</template></span>
            </button>
        </div>
    </div>
</template>
