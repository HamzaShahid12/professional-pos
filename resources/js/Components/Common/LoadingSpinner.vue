<script setup>
defineProps({ small: Boolean, label: String });
</script>

<template>
    <span class="row" role="status">
        <span class="spinner" :style="small ? 'width:14px;height:14px;border-width:2px' : ''" />
        <span v-if="label" class="muted">{{ label }}</span>
    </span>
</template>
