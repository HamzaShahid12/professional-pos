<script setup>
defineProps({ title: { type: String, default: 'Nothing here yet' }, message: String });
</script>

<template>
    <div class="empty">
        <h3>{{ title }}</h3>
        <p v-if="message" style="margin: 0 0 12px">{{ message }}</p>
        <slot />
    </div>
</template>
