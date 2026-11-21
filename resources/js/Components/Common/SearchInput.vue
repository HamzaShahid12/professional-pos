<script setup>
import AppIcon from './AppIcon.vue';

defineProps({ modelValue: { type: String, default: '' }, placeholder: { type: String, default: 'Search…' } });
defineEmits(['update:modelValue']);
</script>

<template>
    <div style="position: relative; min-width: 240px">
        <span style="position: absolute; left: 11px; top: 9px; color: var(--muted)"><AppIcon name="search" :size="16" /></span>
        <input class="input" style="padding-left: 34px" type="search" :value="modelValue" :placeholder="placeholder" @input="$emit('update:modelValue', $event.target.value)">
    </div>
</template>
