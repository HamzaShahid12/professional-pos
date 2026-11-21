<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '../../Layouts/AuthLayout.vue';
import BaseInput from '../../Components/Common/BaseInput.vue';
import BaseButton from '../../Components/Common/BaseButton.vue';

defineOptions({ layout: AuthLayout });
defineProps({ status: String });

const form = useForm({ email: '', password: '', remember: false });
const submit = () => form.post('/login', { onFinish: () => form.reset('password') });
</script>

<template>
    <Head title="Sign in" />
    <h2 style="font-size: 24px; margin-bottom: 4px">Welcome back</h2>
    <p class="muted" style="margin: 0 0 22px">Sign in to your account to continue.</p>
    <p v-if="status" class="badge badge-success" style="margin-bottom: 14px">{{ status }}</p>

    <form @submit.prevent="submit">
        <BaseInput v-model="form.email" label="Email" type="email" autocomplete="username" :error="form.errors.email" required />
        <BaseInput v-model="form.password" label="Password" type="password" autocomplete="current-password" :error="form.errors.password" required />
        <label class="check"><input v-model="form.remember" type="checkbox"> Keep me signed in</label>
        <BaseButton type="submit" :loading="form.processing" style="width: 100%">Sign in</BaseButton>
    </form>
    <p style="text-align: center; margin-top: 18px"><Link href="/forgot-password">Forgot your password?</Link></p>
</template>
