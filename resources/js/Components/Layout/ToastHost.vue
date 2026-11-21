<script setup>
import { useUiStore } from '../../Stores/ui';

const ui = useUiStore();
</script>

<template>
    <div class="toasts" aria-live="polite">
        <div v-for="t in ui.toasts" :key="t.id" class="toast" :class="t.type">
            <span>{{ t.message }}</span>
            <button class="btn btn-ghost btn-sm" type="button" aria-label="Dismiss" @click="ui.dismiss(t.id)">×</button>
        </div>
    </div>
</template>
