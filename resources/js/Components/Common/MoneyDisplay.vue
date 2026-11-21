<script setup>
import { computed } from 'vue';
import { useMoney } from '../../Composables/useMoney';

const props = defineProps({ amount: { type: [String, Number], default: 0 }, currency: Object });
const { money } = useMoney();
const text = computed(() => money(props.amount, props.currency));
</script>

<template>
    <span class="mono">{{ text }}</span>
</template>
