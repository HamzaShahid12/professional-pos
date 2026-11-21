<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '../../Components/Common/PageHeader.vue';
import BaseCard from '../../Components/Common/BaseCard.vue';
import BaseInput from '../../Components/Common/BaseInput.vue';
import BaseButton from '../../Components/Common/BaseButton.vue';
import BaseBadge from '../../Components/Common/BaseBadge.vue';
import { formatDateTime } from '../../Utils/format';

const props = defineProps({ profile: Object, sessions: Array });

const form = useForm({ name: props.profile.name, email: props.profile.email, phone: props.profile.phone ?? '', avatar: null });
const pw = useForm({ current_password: '', password: '', password_confirmation: '' });
const others = useForm({ password: '' });
const fileInput = ref(null);

// POST (not PUT) so the avatar file is sent as multipart form data.
const saveProfile = () => form.post('/profile', { forceFormData: true, preserveScroll: true, onSuccess: () => { form.avatar = null; if (fileInput.value) fileInput.value.value = ''; } });
const savePassword = () => pw.put('/profile/password', { preserveScroll: true, onSuccess: () => pw.reset() });
const logoutOthers = () => others.post('/profile/logout-others', { preserveScroll: true, onSuccess: () => others.reset() });
</script>

<template>
    <Head title="My profile" />
    <PageHeader title="My profile" />

    <div class="grid grid-2">
        <BaseCard title="Profile">
            <form @submit.prevent="saveProfile">
                <BaseInput v-model="form.name" label="Full name" :error="form.errors.name" required />
                <BaseInput v-model="form.email" label="Email" type="email" :error="form.errors.email" required />
                <BaseInput v-model="form.phone" label="Phone" :error="form.errors.phone" />
                <div class="field">
                    <label>Profile image</label>
                    <input ref="fileInput" type="file" accept="image/png,image/jpeg,image/webp" @change="form.avatar = $event.target.files[0]">
                    <span v-if="form.errors.avatar" class="error-text">{{ form.errors.avatar }}</span>
                    <span v-else class="hint">PNG, JPG or WebP, up to 2 MB.</span>
                </div>
                <BaseButton type="submit" :loading="form.processing">Save profile</BaseButton>
            </form>
        </BaseCard>

        <BaseCard title="Change password">
            <form @submit.prevent="savePassword">
                <BaseInput v-model="pw.current_password" label="Current password" type="password" autocomplete="current-password" :error="pw.errors.current_password" required />
                <BaseInput v-model="pw.password" label="New password" type="password" autocomplete="new-password" :error="pw.errors.password" hint="At least 8 characters with letters and numbers." required />
                <BaseInput v-model="pw.password_confirmation" label="Confirm new password" type="password" autocomplete="new-password" required />
                <BaseButton type="submit" :loading="pw.processing">Update password</BaseButton>
            </form>
        </BaseCard>
    </div>

    <BaseCard title="Active sessions" style="margin-top: 16px" flush>
        <table v-if="sessions.length" class="table">
            <thead><tr><th>Device</th><th>IP address</th><th>Last active</th><th /></tr></thead>
            <tbody>
                <tr v-for="(s, i) in sessions" :key="i">
                    <td>{{ s.agent || 'Unknown device' }}</td><td class="mono">{{ s.ip }}</td><td>{{ formatDateTime(s.last_active) }}</td>
                    <td><BaseBadge v-if="s.current" variant="success">This device</BaseBadge></td>
                </tr>
            </tbody>
        </table>
        <div class="card-body">
            <form class="row" @submit.prevent="logoutOthers">
                <input v-model="others.password" class="input" style="max-width: 260px" type="password" placeholder="Confirm your password" required>
                <BaseButton type="submit" variant="secondary" :loading="others.processing">Sign out other devices</BaseButton>
                <span v-if="others.errors.password" class="error-text">{{ others.errors.password }}</span>
            </form>
        </div>
    </BaseCard>
</template>
