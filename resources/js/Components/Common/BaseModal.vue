<script setup>
import { onBeforeUnmount, onMounted } from 'vue';
import AppIcon from './AppIcon.vue';

const props = defineProps({ show: Boolean, title: String, size: { type: String, default: 'md' } });
const emit = defineEmits(['close']);

const onKey = (e) => { if (e.key === 'Escape' && props.show) emit('close'); };
onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>

<template>
    <div v-if="show" class="modal-backdrop" @mousedown.self="emit('close')">
        <div class="modal" :class="size" role="dialog" aria-modal="true">
            <div class="modal-head">
                <span>{{ title }}</span>
                <button class="btn btn-ghost btn-sm" type="button" aria-label="Close" @click="emit('close')"><AppIcon name="x" /></button>
            </div>
            <div class="modal-body"><slot /></div>
            <div v-if="$slots.footer" class="modal-foot"><slot name="footer" /></div>
        </div>
    </div>
</template>
