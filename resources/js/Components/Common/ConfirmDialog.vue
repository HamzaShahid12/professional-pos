<script setup>
import BaseModal from './BaseModal.vue';
import BaseButton from './BaseButton.vue';
import { useConfirm } from '../../Composables/useConfirm';

const { state, answer } = useConfirm();
</script>

<template>
    <BaseModal :show="state.open" :title="state.title" size="sm" @close="answer(false)">
        <p style="margin: 0">{{ state.message }}</p>
        <template #footer>
            <BaseButton variant="secondary" @click="answer(false)">Cancel</BaseButton>
            <BaseButton :variant="state.danger ? 'danger' : 'primary'" @click="answer(true)">{{ state.confirmText }}</BaseButton>
        </template>
    </BaseModal>
</template>
