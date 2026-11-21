<script setup>
import { computed } from 'vue';
import { Bar, Line } from 'vue-chartjs';
import { BarElement, CategoryScale, Chart as ChartJS, Filler, Legend, LinearScale, LineElement, PointElement, Tooltip } from 'chart.js';
import { useMoney } from '../../Composables/useMoney';

ChartJS.register(BarElement, CategoryScale, Filler, Legend, LinearScale, LineElement, PointElement, Tooltip);

const props = defineProps({
    chart: { type: Object, required: true },   // { type: 'bar'|'line', labels: [], datasets: [{ label, data }] }
    money: { type: Boolean, default: true },   // format the value axis and tooltips as money
});
const { money: fmt } = useMoney();

const css = (name, fallback) => (typeof document === 'undefined' ? fallback : getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback);
const data = computed(() => ({
    labels: props.chart.labels,
    datasets: props.chart.datasets.map((d) => ({
        ...d,
        backgroundColor: props.chart.type === 'line' ? `${css('--primary', '#5b4bdb')}22` : css('--primary', '#2459d6'),
        borderColor: css('--primary', '#5b4bdb'),
        borderWidth: props.chart.type === 'line' ? 2 : 0,
        borderRadius: 4,
        fill: props.chart.type === 'line',
        tension: 0.25,
        pointRadius: props.chart.labels.length > 45 ? 0 : 3,
    })),
}));
const options = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: (ctx) => ` ${props.money ? fmt(ctx.parsed.y) : ctx.parsed.y}` } },
    },
    scales: {
        x: { grid: { display: false }, ticks: { maxRotation: 45, autoSkip: true, maxTicksLimit: 14 } },
        y: { beginAtZero: true, ticks: { callback: (v) => (props.money ? fmt(v) : v) } },
    },
}));
const summary = computed(() => `${props.chart.datasets[0]?.label ?? 'Chart'}: ${props.chart.labels.length} points`);
</script>

<template>
    <div style="height: 280px" role="img" :aria-label="summary">
        <Line v-if="chart.type === 'line'" :data="data" :options="options" />
        <Bar v-else :data="data" :options="options" />
    </div>
</template>
