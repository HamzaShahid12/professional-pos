<script setup>
import { computed } from 'vue';
import LoadingSpinner from './LoadingSpinner.vue';

const props = defineProps({
    variant: { type: String, default: 'primary' }, // primary | secondary | danger | ghost
    size: { type: String, default: 'md' },
    type: { type: String, default: 'button' },
    loading: Boolean,
    disabled: Boolean,
});
const cls = computed(() => ['btn', props.variant !== 'primary' && `btn-${props.variant}`, props.size === 'sm' && 'btn-sm']);
</script>

<template>
    <!-- Disabled while loading to prevent duplicate submissions. -->
    <button :type="type" :class="cls" :disabled="disabled || loading">
        <LoadingSpinner v-if="loading" small />
        <slot />
    </button>
</template>
