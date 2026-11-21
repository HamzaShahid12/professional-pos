<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import PageHeader from './PageHeader.vue';
import BaseCard from './BaseCard.vue';
import BaseTable from './BaseTable.vue';
import BaseModal from './BaseModal.vue';
import BaseInput from './BaseInput.vue';
import BaseSelect from './BaseSelect.vue';
import BaseButton from './BaseButton.vue';
import BaseBadge from './BaseBadge.vue';
import { useConfirm } from '../../Composables/useConfirm';

/**
 * Generic list + add/edit modal + delete for simple master data.
 *  fields:  [{ key, label, type: text|textarea|number|select|checkbox, options?, required?, hint?, default? }]
 *  columns: [{ key, label, align? }]  (custom cells via the `cell-<key>` slot)
 *  The form posts to `baseUrl` (create) or PUT `baseUrl/{id}` (edit); delete uses DELETE `baseUrl/{id}`.
 */
const props = defineProps({
    title: { type: String, required: true },
    crumb: String,
    noun: { type: String, required: true },
    baseUrl: { type: String, required: true },
    rows: { type: Array, required: true },
    columns: { type: Array, required: true },
    fields: { type: Array, required: true },
    canDelete: { type: Function, default: () => true },
    intro: String,
});

const { confirm } = useConfirm();
const show = ref(false);
const editing = ref(null);

const blank = () => Object.fromEntries(props.fields.map((f) => [f.key, f.default ?? (f.type === 'checkbox' ? false : '')]));
const form = useForm(blank());

function open(row = null) {
    editing.value = row;
    form.clearErrors();
    const values = blank();
    if (row) props.fields.forEach((f) => { values[f.key] = row[f.key] ?? values[f.key]; });
    form.defaults(values).reset();
    show.value = true;
}

function save() {
    const options = { preserveScroll: true, onSuccess: () => { show.value = false; } };
    editing.value ? form.put(`${props.baseUrl}/${editing.value.id}`, options) : form.post(props.baseUrl, options);
}

async function remove(row) {
    const ok = await confirm({ title: `Delete ${props.noun}`, message: `Delete "${row.name}"? This cannot be undone.`, confirmText: 'Delete', danger: true });
    if (ok) router.delete(`${props.baseUrl}/${row.id}`, { preserveScroll: true });
}

const heading = computed(() => `${editing.value ? 'Edit' : 'Add'} ${props.noun}`);
</script>

<template>
    <PageHeader :title="title" :crumb="crumb"><BaseButton @click="open()">Add {{ noun }}</BaseButton></PageHeader>
    <p v-if="intro" class="muted" style="margin-top: -6px">{{ intro }}</p>

    <BaseCard flush>
        <BaseTable :columns="columns" :rows="rows" :empty-title="`No ${title.toLowerCase()} yet`">
            <template v-for="c in columns" :key="c.key" #[`cell-${c.key}`]="{ row, value }">
                <slot :name="`cell-${c.key}`" :row="row" :value="value">
                    <BaseBadge v-if="typeof value === 'boolean'" :variant="value ? 'success' : 'neutral'">{{ c.key === 'is_active' ? (value ? 'Active' : 'Inactive') : (value ? 'Yes' : 'No') }}</BaseBadge>
                    <template v-else>{{ value ?? '—' }}</template>
                </slot>
            </template>
            <template #actions="{ row }">
                <BaseButton size="sm" variant="secondary" @click="open(row)">Edit</BaseButton>
                <BaseButton v-if="canDelete(row)" size="sm" variant="ghost" @click="remove(row)">Delete</BaseButton>
            </template>
        </BaseTable>
    </BaseCard>

    <BaseModal :show="show" :title="heading" @close="show = false">
        <form id="crud-form" @submit.prevent="save">
            <template v-for="f in fields" :key="f.key">
                <label v-if="f.type === 'checkbox'" class="check"><input v-model="form[f.key]" type="checkbox"> {{ f.label }}</label>
                <div v-else-if="f.type === 'textarea'" class="field">
                    <label>{{ f.label }}</label>
                    <textarea v-model="form[f.key]" class="textarea" rows="3" />
                    <span v-if="form.errors[f.key]" class="error-text">{{ form.errors[f.key] }}</span>
                </div>
                <BaseSelect v-else-if="f.type === 'select'" v-model="form[f.key]" :label="f.label" :options="f.options" placeholder="—" :error="form.errors[f.key]" />
                <BaseInput v-else v-model="form[f.key]" :label="f.label" :type="f.type ?? 'text'" :required="f.required" :hint="f.hint" :error="form.errors[f.key]" />
                <span v-if="f.type === 'checkbox' && form.errors[f.key]" class="error-text">{{ form.errors[f.key] }}</span>
            </template>
            <span v-for="key in ['category', 'brand', 'unit', 'tax', 'warehouse']" :key="key" class="error-text" style="display: block">{{ form.errors[key] }}</span>
        </form>
        <template #footer>
            <BaseButton variant="secondary" @click="show = false">Cancel</BaseButton>
            <BaseButton type="submit" form="crud-form" :loading="form.processing">Save</BaseButton>
        </template>
    </BaseModal>
</template>
