<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppIcon from '../Common/AppIcon.vue';
import { usePermissions } from '../../Composables/usePermissions';
import { useUiStore } from '../../Stores/ui';
import { visibleNavigation } from './navigation';

const page = usePage();
const ui = useUiStore();
const { can } = usePermissions();
const groups = computed(() => visibleNavigation(can));
const isActive = (href) => page.url === href || page.url.startsWith(`${href}/`) || page.url.startsWith(`${href}?`);
</script>

<template>
    <aside class="sidebar" :class="{ open: ui.sidebarOpen }">
        <div class="sidebar-brand">
            <span class="brand-mark"><AppIcon name="building" :size="18" /></span>
            Professional POS
        </div>
        <nav class="sidebar-nav">
            <template v-for="g in groups" :key="g.group">
                <div class="nav-group">{{ g.group }}</div>
                <Link v-for="item in g.items" :key="item.href" :href="item.href" class="nav-link" :class="{ active: isActive(item.href) }" @click="ui.closeSidebar()">
                    <AppIcon :name="item.icon" />{{ item.label }}
                </Link>
            </template>
        </nav>
    </aside>
</template>
