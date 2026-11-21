<script setup>
defineProps({ title: { type: String, required: true }, crumb: String });
</script>

<template>
    <div class="page-head">
        <div>
            <div class="breadcrumb">Home<template v-if="crumb"> / {{ crumb }}</template> / {{ title }}</div>
            <h1 class="page-title">{{ title }}</h1>
        </div>
        <div class="row"><slot /></div>
    </div>
</template>
