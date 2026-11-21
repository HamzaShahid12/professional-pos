<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '../../Layouts/AuthLayout.vue';
import BaseInput from '../../Components/Common/BaseInput.vue';
import BaseButton from '../../Components/Common/BaseButton.vue';

defineOptions({ layout: AuthLayout });
defineProps({ status: String });

const form = useForm({ email: '' });
</script>

<template>
    <Head title="Forgot password" />
    <h2 style="font-size: 24px; margin-bottom: 4px">Reset your password</h2>
    <p class="muted" style="margin: 0 0 22px">Enter your email and we will send you a reset link.</p>
    <p v-if="status" class="badge badge-success" style="margin-bottom: 14px; white-space: normal">{{ status }}</p>

    <form @submit.prevent="form.post('/forgot-password')">
        <BaseInput v-model="form.email" label="Email" type="email" :error="form.errors.email" required />
        <BaseButton type="submit" :loading="form.processing" style="width: 100%">Email reset link</BaseButton>
    </form>
    <p style="text-align: center; margin-top: 18px"><Link href="/login">Back to sign in</Link></p>
</template>
