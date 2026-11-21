<script setup>
import { useId } from 'vue';

/** Decimal text input: keeps the value a STRING (no float rounding) and limits it to the currency's decimals. */
const props = defineProps({
    modelValue: { type: [String, Number], default: '' },
    label: String,
    error: String,
    decimals: { type: Number, default: 2 },
    symbol: String,
    required: Boolean,
});
const emit = defineEmits(['update:modelValue']);
const id = useId();

function onInput(e) {
    let v = e.target.value.replace(/[^0-9.]/g, '');
    const [int, ...rest] = v.split('.');
    v = rest.length ? `${int}.${rest.join('').slice(0, props.decimals)}` : int;
    e.target.value = v;
    emit('update:modelValue', v);
}
</script>

<template>
    <div class="field">
        <label v-if="label" :for="id">{{ label }}<span v-if="required" class="error-text"> *</span></label>
        <div style="position: relative">
            <span v-if="symbol" style="position: absolute; left: 12px; top: 9px; color: var(--muted)">{{ symbol }}</span>
            <input :id="id" class="input mono" :class="{ invalid: error }" :style="symbol ? 'padding-left: 42px' : ''" inputmode="decimal" :value="modelValue" @input="onInput">
        </div>
        <span v-if="error" class="error-text">{{ error }}</span>
    </div>
</template>
