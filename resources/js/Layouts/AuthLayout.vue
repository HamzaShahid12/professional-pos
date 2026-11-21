<script setup>
import AppIcon from '../Components/Common/AppIcon.vue';
</script>

<template>
    <div class="auth-wrap">
        <div class="auth-side">
            <div class="row" style="margin-bottom: 28px"><span class="brand-mark" style="background: rgba(255,255,255,.18)"><AppIcon name="building" /></span><strong>Professional POS</strong></div>
            <h1>Run every branch from one place.</h1>
            <p style="max-width: 420px; opacity: .85">Retail POS, inventory, multi-currency sales and double-entry accounting for growing shops.</p>
        </div>
        <div class="auth-form"><div class="auth-box"><slot /></div></div>
    </div>
</template>
