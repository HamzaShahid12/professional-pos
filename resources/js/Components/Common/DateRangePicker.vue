<script setup>
import { computed } from 'vue';

/** v-model: { from: 'YYYY-MM-DD', to: 'YYYY-MM-DD' }; preset buttons fill both dates. */
const props = defineProps({ modelValue: { type: Object, default: () => ({ from: '', to: '' }) } });
const emit = defineEmits(['update:modelValue']);

// Local-date formatting (toISOString would shift the day for timezones ahead of UTC, e.g. Asia/Karachi).
const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const presets = computed(() => {
    const today = new Date();
    const back = (n) => { const d = new Date(today); d.setDate(d.getDate() - n); return d; };
    return [
        { label: 'Today', from: iso(today), to: iso(today) },
        { label: 'Yesterday', from: iso(back(1)), to: iso(back(1)) },
        { label: 'Last 7 days', from: iso(back(6)), to: iso(today) },
        { label: 'Last 30 days', from: iso(back(29)), to: iso(today) },
        { label: 'This month', from: iso(new Date(today.getFullYear(), today.getMonth(), 1)), to: iso(today) },
    ];
});
const set = (patch) => emit('update:modelValue', { ...props.modelValue, ...patch });
</script>

<template>
    <div class="row">
        <input class="input" style="width: 150px" type="date" :value="modelValue.from" @input="set({ from: $event.target.value })">
        <span class="muted">to</span>
        <input class="input" style="width: 150px" type="date" :value="modelValue.to" @input="set({ to: $event.target.value })">
        <select class="select" style="width: 140px" @change="(e) => { const p = presets[e.target.value]; if (p) emit('update:modelValue', { from: p.from, to: p.to }); e.target.value = ''; }">
            <option value="">Quick range</option>
            <option v-for="(p, i) in presets" :key="p.label" :value="i">{{ p.label }}</option>
        </select>
    </div>
</template>
