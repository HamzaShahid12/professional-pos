<script setup>
import { computed, useId } from 'vue';

const props = defineProps({
    modelValue: { type: [String, Number, null], default: '' },
    options: { type: Array, default: () => [] }, // strings or { value, label }
    label: String,
    error: String,
    placeholder: String,
    disabled: Boolean,
});
defineEmits(['update:modelValue']);
const id = useId();
const normalized = computed(() => props.options.map((o) => (typeof o === 'object' ? o : { value: o, label: o })));
</script>

<template>
    <div class="field">
        <label v-if="label" :for="id">{{ label }}</label>
        <select :id="id" class="select" :class="{ invalid: error }" :value="modelValue" :disabled="disabled" @change="$emit('update:modelValue', $event.target.value)">
            <option v-if="placeholder !== undefined" value="">{{ placeholder }}</option>
            <option v-for="o in normalized" :key="o.value" :value="o.value">{{ o.label }}</option>
        </select>
        <span v-if="error" class="error-text">{{ error }}</span>
    </div>
</template>
