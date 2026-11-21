<script setup>
import { ref, useId } from 'vue';
import AppIcon from './AppIcon.vue';

const props = defineProps({
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
const visible = ref(false); // password fields get a show/hide toggle
</script>

<template>
    <div class="field">
        <label v-if="label" :for="id">{{ label }}<span v-if="required" class="error-text"> *</span></label>
        <div v-if="type === 'password'" class="input-wrap">
            <input
                :id="id" class="input has-toggle" :class="{ invalid: error }" :type="visible ? 'text' : 'password'" :value="modelValue" :placeholder="placeholder"
                :required="required" :disabled="disabled" :autocomplete="autocomplete"
                @input="$emit('update:modelValue', $event.target.value)"
            >
            <button type="button" class="input-toggle" :aria-label="visible ? 'Hide password' : 'Show password'" :aria-pressed="visible" tabindex="-1" @click="visible = !visible">
                <AppIcon :name="visible ? 'eye-off' : 'eye'" :size="18" />
            </button>
        </div>
        <input
            v-else :id="id" class="input" :class="{ invalid: error }" :type="type" :value="modelValue" :placeholder="placeholder"
            :required="required" :disabled="disabled" :autocomplete="autocomplete"
            @input="$emit('update:modelValue', $event.target.value)"
        >
        <span v-if="error" class="error-text">{{ error }}</span>
        <span v-else-if="hint" class="hint">{{ hint }}</span>
    </div>
</template>
