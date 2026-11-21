<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PageHeader from '../../Components/Common/PageHeader.vue';
import BaseCard from '../../Components/Common/BaseCard.vue';

defineProps({ groups: Object });
</script>

<template>
    <Head title="Reports" />
    <PageHeader title="Reports" />
    <BaseCard v-for="(reports, group) in groups" :key="group" :title="group" style="margin-bottom: 16px">
        <div class="grid grid-3">
            <Link v-for="r in reports" :key="r.key" :href="`/reports/${r.key}`" class="report-link">
                <strong>{{ r.title }}</strong>
                <span class="muted">{{ r.description }}</span>
            </Link>
        </div>
    </BaseCard>
    <p class="muted">Profit and loss, the balance sheet and the trial balance are under <Link href="/accounting">Accounting</Link>.</p>
</template>

<style scoped>
.report-link { display: flex; flex-direction: column; gap: 4px; padding: 12px 14px; border: 1px solid var(--border); border-radius: var(--radius); color: inherit; text-decoration: none; }
.report-link:hover { border-color: var(--primary); background: var(--primary-50); }
</style>
