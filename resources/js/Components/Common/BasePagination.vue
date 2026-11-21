<script setup>
import { Link } from '@inertiajs/vue3';

/** Accepts a Laravel paginator object (meta + links) as serialised by Inertia. */
defineProps({ paginator: { type: Object, required: true } });
</script>

<template>
    <div v-if="paginator.last_page > 1 || paginator.total > 0" class="pagination">
        <span>Showing {{ paginator.from ?? 0 }}–{{ paginator.to ?? 0 }} of {{ paginator.total }}</span>
        <div v-if="paginator.last_page > 1" class="page-links">
            <template v-for="(link, i) in paginator.links" :key="i">
                <Link v-if="link.url" :href="link.url" class="page-link" :class="{ active: link.active }" preserve-scroll preserve-state>
                    <span v-html="link.label" />
                </Link>
                <span v-else class="page-link disabled" v-html="link.label" />
            </template>
        </div>
    </div>
</template>
