<script setup>
import AppIcon from '../Components/Common/AppIcon.vue';
</script>

<template>
    <div class="auth-wrap">
        <div class="auth-card">
            <div class="auth-brand">
                <span class="brand-mark"><AppIcon name="building" :size="20" /></span>
                <div><strong>Professional POS</strong><div class="muted" style="font-size: 12px">Retail, inventory and accounting</div></div>
            </div>
            <slot />
        </div>
        <p class="auth-foot">&copy; {{ new Date().getFullYear() }} Professional POS</p>
    </div>
</template>
