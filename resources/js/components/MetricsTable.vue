<template>
    <table class="w-full text-sm tabular-nums">
        <thead>
            <tr class="text-left text-xs text-[var(--tg-theme-hint-color,#6b7280)]">
                <th class="py-1.5 pr-2 font-medium">Metric</th>
                <th
                    v-for="col in columns"
                    :key="col.key"
                    class="py-1.5 pr-2 font-medium text-right"
                >{{ col.label }}</th>
            </tr>
        </thead>
        <tbody>
            <tr
                v-for="m in metrics"
                :key="m.key"
                class="border-t border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
            >
                <td class="py-1.5 pr-2">{{ m.label }}</td>
                <td
                    v-for="col in columns"
                    :key="col.key"
                    class="py-1.5 pr-2 text-right"
                >{{ format(col.values[m.key], m.key) }}</td>
            </tr>
        </tbody>
    </table>
</template>

<script setup>
const props = defineProps({
    /** array of { key, label, values: { metricKey: value } } */
    columns: { type: Array, required: true },
});

const metrics = [
    { key: 'clicks', label: 'clicks' },
    { key: 'lp_ctr', label: 'LP CTR' },
    { key: 'leads', label: 'leads' },
    { key: 'ftds_real', label: 'FTDs' },
    { key: 'real_cr', label: 'CR%' },
    { key: 'interest_rate', label: 'interest' },
    { key: 'scrolling', label: 'scroll' },
];

const RATE_KEYS = new Set(['lp_ctr', 'real_cr', 'interest_rate', 'scrolling']);

function format(value, key) {
    if (value === null || value === undefined) return '—';
    if (RATE_KEYS.has(key)) return Number(value).toFixed(2);
    if (Number.isInteger(value)) return value.toLocaleString();
    return Number(value).toFixed(2);
}
</script>
