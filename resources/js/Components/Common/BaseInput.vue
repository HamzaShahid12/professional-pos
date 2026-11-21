<script setup>
import { useId } from 'vue';

defineProps({
    modelValue: { type: [String, Number, null], default: '' },
    label: String,
    type: { type: String, default: 'text' },
    error: String,
    hint: String,
    placeholder: String,
    required: Boolean,
    disabled: Boolean,
    autocomplete: String,
});
defineEmits(['update:modelValue']);
const id = useId();
</script>

<template>
    <div class="field">
        <label v-if="label" :for="id">{{ label }}<span v-if="required" class="error-text"> *</span></label>
        <input
            :id="id" class="input" :class="{ invalid: error }" :type="type" :value="modelValue" :placeholder="placeholder"
            :required="required" :disabled="disabled" :autocomplete="autocomplete"
            @input="$emit('update:modelValue', $event.target.value)"
        >
        <span v-if="error" class="error-text">{{ error }}</span>
        <span v-else-if="hint" class="hint">{{ hint }}</span>
    </div>
</template>
