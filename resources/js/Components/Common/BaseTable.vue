<script setup>
import EmptyState from './EmptyState.vue';
import LoadingSpinner from './LoadingSpinner.vue';

/** columns: [{ key, label, align? }]. Customise cells with the `cell-<key>` slot; row actions via `actions`. */
defineProps({
    columns: { type: Array, required: true },
    rows: { type: Array, default: () => [] },
    loading: Boolean,
    emptyTitle: { type: String, default: 'No records found' },
    emptyMessage: String,
    rowKey: { type: String, default: 'id' },
});
</script>

<template>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th v-for="c in columns" :key="c.key" :class="{ 'text-right': c.align === 'right' }">{{ c.label }}</th>
                    <th v-if="$slots.actions" class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="loading"><td :colspan="columns.length + 1"><LoadingSpinner label="Loading…" /></td></tr>
                <tr v-for="row in rows" v-else :key="row[rowKey]">
                    <td v-for="c in columns" :key="c.key" :class="{ 'text-right': c.align === 'right' }">
                        <slot :name="`cell-${c.key}`" :row="row" :value="row[c.key]">{{ row[c.key] }}</slot>
                    </td>
                    <td v-if="$slots.actions" class="text-right"><slot name="actions" :row="row" /></td>
                </tr>
            </tbody>
        </table>
        <EmptyState v-if="!loading && rows.length === 0" :title="emptyTitle" :message="emptyMessage"><slot name="empty" /></EmptyState>
    </div>
</template>
