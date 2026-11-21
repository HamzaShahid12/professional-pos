<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AuthLayout from '../../Layouts/AuthLayout.vue';
import BaseInput from '../../Components/Common/BaseInput.vue';
import BaseButton from '../../Components/Common/BaseButton.vue';

defineOptions({ layout: AuthLayout });
const props = defineProps({ token: String, email: String });

const form = useForm({ token: props.token, email: props.email ?? '', password: '', password_confirmation: '' });
</script>

<template>
    <Head title="Choose a new password" />
    <h2 style="font-size: 24px; margin-bottom: 22px">Choose a new password</h2>
    <form @submit.prevent="form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') })">
        <BaseInput v-model="form.email" label="Email" type="email" :error="form.errors.email" required />
        <BaseInput v-model="form.password" label="New password" type="password" hint="At least 8 characters with letters and numbers." :error="form.errors.password" required />
        <BaseInput v-model="form.password_confirmation" label="Confirm password" type="password" required />
        <BaseButton type="submit" :loading="form.processing" style="width: 100%">Reset password</BaseButton>
    </form>
</template>
