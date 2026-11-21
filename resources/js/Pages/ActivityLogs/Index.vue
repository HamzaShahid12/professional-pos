<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import PageHeader from '../../Components/Common/PageHeader.vue';
import BaseCard from '../../Components/Common/BaseCard.vue';
import BaseTable from '../../Components/Common/BaseTable.vue';
import BasePagination from '../../Components/Common/BasePagination.vue';
import SearchInput from '../../Components/Common/SearchInput.vue';
import { useDebouncedSearch } from '../../Composables/useDebouncedSearch';
import { formatDateTime, humanize } from '../../Utils/format';

const props = defineProps({ logs: Object, filters: Object });
const { search } = useDebouncedSearch('/activity-logs', props.filters.search);
const tz = usePage().props.tenant?.business.timezone;

const columns = [
    { key: 'at', label: 'When' }, { key: 'user', label: 'User' }, { key: 'action', label: 'Action' }, { key: 'ip', label: 'IP address' },
];
</script>

<template>
    <Head title="Activity logs" />
    <PageHeader title="Activity logs" />

    <BaseCard flush>
        <div class="toolbar"><SearchInput v-model="search" placeholder="Filter by action, e.g. branch" /></div>
        <BaseTable :columns="columns" :rows="logs.data" empty-title="No activity recorded">
            <template #cell-at="{ row }">{{ formatDateTime(row.at, tz) }}</template>
            <template #cell-user="{ row }">{{ row.user ?? 'System' }}</template>
            <template #cell-action="{ row }">{{ humanize(row.action) }} <span class="muted mono">({{ row.action }})</span></template>
            <template #cell-ip="{ row }"><span class="mono">{{ row.ip ?? '—' }}</span></template>
        </BaseTable>
        <BasePagination :paginator="logs" />
    </BaseCard>
</template>
