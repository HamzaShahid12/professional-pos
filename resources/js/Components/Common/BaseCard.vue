<script setup>
defineProps({ title: String, flush: Boolean });
</script>

<template>
    <section class="card">
        <header v-if="title || $slots.header" class="card-head">
            <span>{{ title }}</span>
            <slot name="header" />
        </header>
        <div :class="flush ? '' : 'card-body'"><slot /></div>
    </section>
</template>
