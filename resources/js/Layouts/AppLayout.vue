<script setup>
import { computed, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppSidebar from '../Components/Layout/AppSidebar.vue';
import AppHeader from '../Components/Layout/AppHeader.vue';
import ToastHost from '../Components/Layout/ToastHost.vue';
import ConfirmDialog from '../Components/Common/ConfirmDialog.vue';
import { useUiStore } from '../Stores/ui';

const page = usePage();
const ui = useUiStore();

// Server flash messages and validation failures become toasts.
watch(() => page.props.flash, (f) => {
    if (f?.success) ui.success(f.success);
    if (f?.error) ui.error(f.error);
}, { immediate: true, deep: true });

const firstError = computed(() => Object.values(page.props.errors ?? {})[0]);
watch(firstError, (msg) => { if (msg) ui.error(msg); });
</script>

<template>
    <div class="app-shell">
        <AppSidebar />
        <div class="backdrop" :class="{ open: ui.sidebarOpen }" @click="ui.closeSidebar()" />
        <div class="main">
            <AppHeader />
            <main class="content"><slot /></main>
        </div>
        <ToastHost />
        <ConfirmDialog />
    </div>
</template>
