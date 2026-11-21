<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, watch } from 'vue';
import PageHeader from '../../Components/Common/PageHeader.vue';
import BaseCard from '../../Components/Common/BaseCard.vue';
import BaseButton from '../../Components/Common/BaseButton.vue';
import DateRangePicker from '../../Components/Common/DateRangePicker.vue';
import MoneyDisplay from '../../Components/Common/MoneyDisplay.vue';
import ReportChart from '../../Components/Charts/ReportChart.vue';
import { formatQty } from '../../Utils/format';

const props = defineProps({ type: String, report: Object, filters: Object, uses_dates: Boolean, uses_branch: Boolean, branches: Array, can_export: Boolean, generated_at: String });

const range = reactive({ from: props.filters.from, to: props.filters.to });
const branch = reactive({ id: props.filters.branch_id ?? '' });
const query = computed(() => {
    const q = {};
    if (props.uses_dates) { q.from = range.from; q.to = range.to; }
    if (props.uses_branch && branch.id) q.branch_id = branch.id;
    return q;
});
const qs = computed(() => new URLSearchParams(query.value).toString());
let timer = null;
watch([range, branch], () => {
    clearTimeout(timer);
    timer = setTimeout(() => { if (!props.uses_dates || (range.from && range.to && range.from <= range.to)) router.get(`/reports/${props.type}`, query.value, { preserveState: true, preserveScroll: true, replace: true }); }, 300);
}, { deep: true });

const printNow = () => window.print();
const align = (c) => (['money', 'qty', 'int', 'percent'].includes(c.type) ? 'right' : 'left');
const isMoney = computed(() => props.report.chart && !['stock_low'].includes(props.type) && props.report.chart.datasets[0]?.label !== 'Quantity');
</script>

<template>
    <Head :title="report.title" />
    <PageHeader :title="report.title">
        <Link href="/reports" class="btn btn-secondary no-print">All reports</Link>
        <a v-if="can_export" :href="`/reports/${type}/export${qs ? `?${qs}` : ''}`" class="btn btn-secondary no-print">Export CSV</a>
        <BaseButton variant="secondary" class="no-print" @click="printNow">Print</BaseButton>
    </PageHeader>

    <BaseCard v-if="uses_dates || uses_branch" class="no-print" style="margin-bottom: 16px">
        <div class="row" style="align-items: flex-end; flex-wrap: wrap">
            <DateRangePicker v-if="uses_dates" v-model="range" />
            <select v-if="uses_branch && branches.length > 1" v-model="branch.id" class="select" style="width: 180px" aria-label="Branch">
                <option value="">All my branches</option>
                <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
            </select>
        </div>
    </BaseCard>

    <p class="muted print-only">{{ report.title }}<template v-if="uses_dates"> · {{ filters.from }} to {{ filters.to }}</template> · generated {{ generated_at }}</p>

    <BaseCard v-if="report.chart" style="margin-bottom: 16px"><ReportChart :chart="report.chart" :money="isMoney" /></BaseCard>

    <BaseCard flush>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th v-for="c in report.columns" :key="c.key" :style="{ textAlign: align(c) }">{{ c.label }}</th></tr></thead>
                <tbody>
                    <tr v-if="!report.rows.length"><td :colspan="report.columns.length" class="muted">Nothing to show for this selection.</td></tr>
                    <tr v-for="(row, i) in report.rows" :key="i">
                        <td v-for="c in report.columns" :key="c.key" :style="{ textAlign: align(c) }">
                            <MoneyDisplay v-if="c.type === 'money'" :amount="row[c.key]" />
                            <template v-else-if="c.type === 'qty'">{{ formatQty(row[c.key]) }}</template>
                            <template v-else-if="c.type === 'percent'">{{ row[c.key] }}%</template>
                            <template v-else>{{ row[c.key] }}</template>
                        </td>
                    </tr>
                </tbody>
                <tfoot v-if="report.totals && report.rows.length">
                    <tr>
                        <th v-for="c in report.columns" :key="c.key" :style="{ textAlign: align(c) }">
                            <template v-if="report.totals[c.key] === undefined || report.totals[c.key] === ''" />
                            <MoneyDisplay v-else-if="c.type === 'money'" :amount="report.totals[c.key]" />
                            <template v-else-if="c.type === 'qty'">{{ formatQty(report.totals[c.key]) }}</template>
                            <template v-else-if="c.type === 'percent'">{{ report.totals[c.key] }}%</template>
                            <template v-else>{{ report.totals[c.key] }}</template>
                        </th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </BaseCard>

    <p v-if="report.truncated" class="muted">Showing the first {{ report.rows.length }} of {{ report.total_rows }} rows. Export the CSV for every row.</p>
    <p v-for="n in report.notes ?? []" :key="n" class="muted">{{ n }}</p>
</template>

<style scoped>
.print-only { display: none; }
@media print {
    .print-only { display: block; }
    .no-print { display: none !important; }
}
</style>
