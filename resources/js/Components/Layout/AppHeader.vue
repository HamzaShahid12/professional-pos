<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AppIcon from '../Common/AppIcon.vue';
import { useUiStore } from '../../Stores/ui';
import { initials } from '../../Utils/format';

const page = usePage();
const ui = useUiStore();
const user = computed(() => page.props.auth.user);
const tenant = computed(() => page.props.tenant);
const menuOpen = ref(false);
const root = ref(null);

function switchContext(payload) {
    router.post('/context', payload, { preserveScroll: true, onSuccess: () => router.reload() });
}
const onBusiness = (e) => switchContext({ business_id: Number(e.target.value) });
const onBranch = (e) => switchContext({ branch_id: Number(e.target.value) });
const logout = () => router.post('/logout');

const close = (e) => { if (root.value && !root.value.contains(e.target)) menuOpen.value = false; };
onMounted(() => document.addEventListener('click', close));
onBeforeUnmount(() => document.removeEventListener('click', close));
</script>

<template>
    <header class="header">
        <button class="btn btn-ghost menu-btn" type="button" aria-label="Menu" @click="ui.toggleSidebar()"><AppIcon name="menu" /></button>

        <div v-if="tenant" class="row">
            <select v-if="tenant.businesses.length > 1" class="select" style="width: 190px" :value="tenant.business.id" aria-label="Business" @change="onBusiness">
                <option v-for="b in tenant.businesses" :key="b.id" :value="b.id">{{ b.name }}</option>
            </select>
            <strong v-else style="color: var(--navy-900)">{{ tenant.business.name }}</strong>

            <select v-if="tenant.branches.length" class="select" style="width: 170px" :value="tenant.branch_id" aria-label="Branch" @change="onBranch">
                <option v-for="b in tenant.branches" :key="b.id" :value="b.id">{{ b.name }}</option>
            </select>
        </div>

        <div class="header-spacer" />

        <div ref="root" style="position: relative">
            <button class="btn btn-ghost" type="button" @click.stop="menuOpen = !menuOpen">
                <span class="avatar"><img v-if="user.avatar_url" :src="user.avatar_url" alt=""><template v-else>{{ initials(user.name) }}</template></span>
                <span style="text-align: left; line-height: 1.2">
                    <span style="display: block; color: var(--text)">{{ user.name }}</span>
                    <small class="muted">{{ user.roles[0] }}</small>
                </span>
                <AppIcon name="chevron" :size="14" />
            </button>
            <div v-if="menuOpen" class="card" style="position: absolute; right: 0; top: 48px; min-width: 190px; padding: 6px; z-index: 50">
                <Link href="/profile" class="nav-link" style="color: var(--text)" @click="menuOpen = false"><AppIcon name="settings" /> My profile</Link>
                <button class="nav-link" style="color: var(--danger); width: 100%; border: 0; background: none; cursor: pointer" type="button" @click="logout">
                    <AppIcon name="logout" /> Sign out
                </button>
            </div>
        </div>
    </header>
</template>
